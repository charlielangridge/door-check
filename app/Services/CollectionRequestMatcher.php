<?php

namespace App\Services;

use App\ChildCollectionState;
use App\CollectionRequestMatchPerson;
use App\CollectionRequestStatus;
use App\Models\CollectionRequest;
use App\Settings\CollectionMatchingSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class CollectionRequestMatcher
{
    public function __construct(
        private readonly CollectionRequestCandidateFinder $finder,
        private readonly CollectionRequestNameScorer $scorer,
        private readonly CollectionDuplicateChecks $duplicates,
    ) {}

    public function match(int $requestId, CollectionRequestMatchPerson $person): void
    {
        $request = DB::transaction(function () use ($requestId, $person): ?CollectionRequest {
            $request = $this->lockRequest($requestId);

            if (! $request || $request->collection_state !== ChildCollectionState::NeedsChecking || $request->status->isComplete() || $request->getAttribute($person->statusColumn())->isComplete()) {
                return null;
            }

            if ($request->getAttribute($person->idColumn()) !== null) {
                $request->setAttribute($person->statusColumn(), CollectionRequestStatus::Matched);
                $this->saveOverallStatus($request);

                return null;
            }

            $threshold = $request->matching_threshold ?? resolve(CollectionMatchingSettings::class)->automatic_matching_threshold;

            throw_if(! is_finite($threshold) || $threshold <= 0 || $threshold > 100, UnexpectedValueException::class, 'The automatic matching threshold must be greater than zero and at most 100.');

            $request->matching_threshold = $threshold;
            $request->setAttribute($person->statusColumn(), CollectionRequestStatus::Processing);
            $this->saveOverallStatus($request);

            return $request;
        });

        if ($request === null) {
            return;
        }

        $name = $request->getAttribute($person->nameColumn());
        $result = $this->finder->find($person, $name, $request->site_id);
        $candidates = $this->scorer->score($name, $result['candidates']);
        usort($candidates, fn (array $left, array $right): int => ($right['confidence'] <=> $left['confidence']) ?: ($left['id'] <=> $right['id']));

        DB::transaction(function () use ($requestId, $person, $name, $candidates, $result): void {
            $request = $this->lockRequest($requestId);

            if (! $request || $request->collection_state !== ChildCollectionState::NeedsChecking || $request->status->isComplete() || $request->getAttribute($person->statusColumn())->isComplete()) {
                return;
            }

            if ($request->getAttribute($person->idColumn()) !== null) {
                $request->setAttribute($person->statusColumn(), CollectionRequestStatus::Matched);
                $this->saveOverallStatus($request);

                return;
            }

            throw_if($request->getAttribute($person->nameColumn()) !== $name, UnexpectedValueException::class, 'The submitted name changed while matching was in progress.');

            $qualified = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['confidence'] >= $request->matching_threshold));
            $candidateChildId = $person === CollectionRequestMatchPerson::Child && count($qualified) === 1 ? $qualified[0]['id'] : null;
            if ($candidateChildId !== null && $candidateChildId < 1) {
                throw new UnexpectedValueException('A matched child must have a valid record ID.');
            }
            $duplicate = $candidateChildId !== null && $this->duplicates->conflicts($request, $candidateChildId);
            $accepted = count($qualified) === 1 && ! $result['truncated'] && ! $duplicate;
            if ($person === CollectionRequestMatchPerson::Child) {
                $request->duplicate_child_match = $duplicate;
                $request->duplicate_child_id = $duplicate ? $candidateChildId : null;
            }
            $prefix = $person->value;

            $request->setAttribute($prefix.'_match_candidates', $candidates);
            $request->setAttribute($prefix.'_match_confidence', $candidates[0]['confidence'] ?? null);
            $request->setAttribute($prefix.'_candidates_truncated', $result['truncated']);

            if ($accepted) {
                $request->setAttribute($person->idColumn(), $qualified[0]['id']);
                $status = CollectionRequestStatus::Matched;
            } else {
                $status = $candidates === [] ? CollectionRequestStatus::NoMatchFound : CollectionRequestStatus::NeedsApproval;
            }

            $request->setAttribute($person->statusColumn(), $status);
            $this->saveOverallStatus($request);
            $this->duplicates->refresh($request);
        });
    }

    public function fail(int $requestId, CollectionRequestMatchPerson $person): void
    {
        DB::transaction(function () use ($requestId, $person): void {
            $request = $this->lockRequest($requestId);

            if (! $request || $request->collection_state !== ChildCollectionState::NeedsChecking || $request->status->isComplete() || $request->getAttribute($person->statusColumn())->isComplete()) {
                return;
            }

            $request->setAttribute($person->statusColumn(), $request->getAttribute($person->idColumn()) !== null
                ? CollectionRequestStatus::Matched
                : CollectionRequestStatus::MatchingFailed);
            $this->saveOverallStatus($request);
        });
    }

    private function saveOverallStatus(CollectionRequest $request): void
    {
        foreach (CollectionRequestMatchPerson::cases() as $person) {
            $id = $request->getAttribute($person->idColumn());

            if ($id !== null && ! $this->isSiteMember($request, $person, $id)) {
                $request->setAttribute($person->idColumn(), null);
                $request->setAttribute($person->statusColumn(), CollectionRequestStatus::NeedsApproval);
                $request->setAttribute($person->value.'_site_membership_changed', true);
            }
        }

        $statuses = [$request->adult_match_status, $request->child_match_status];

        if (in_array(CollectionRequestStatus::Pending, $statuses, true) || in_array(CollectionRequestStatus::Processing, $statuses, true)) {
            $request->status = CollectionRequestStatus::Processing;
        } elseif (in_array(CollectionRequestStatus::MatchingFailed, $statuses, true)) {
            $request->status = CollectionRequestStatus::MatchingFailed;
        } elseif ($request->adult_id !== null && $request->requested_child_id !== null) {
            $related = $request->adult()->whereHas('children', fn (Builder $query): Builder => $query->whereKey($request->requested_child_id))->exists();
            $request->status = $related ? CollectionRequestStatus::Matched : CollectionRequestStatus::NeedsApproval;
        } elseif ($statuses === [CollectionRequestStatus::NoMatchFound, CollectionRequestStatus::NoMatchFound]) {
            $request->status = CollectionRequestStatus::NoMatchFound;
        } else {
            $request->status = CollectionRequestStatus::NeedsApproval;
        }

        $request->save();
    }

    private function lockRequest(int $requestId): ?CollectionRequest
    {
        $parentId = CollectionRequest::query()->whereKey($requestId)->value('parent_request_id');
        $root = CollectionRequest::query()->whereKey($parentId ?? $requestId)->lockForUpdate()->first();

        return $parentId === null ? $root : CollectionRequest::query()->lockForUpdate()->find($requestId);
    }

    private function isSiteMember(CollectionRequest $request, CollectionRequestMatchPerson $person, int $personId): bool
    {
        return DB::table('site_'.$person->value)
            ->where('site_id', $request->site_id)
            ->where($person->value.'_id', $personId)
            ->lockForUpdate()
            ->first() !== null;
    }
}
