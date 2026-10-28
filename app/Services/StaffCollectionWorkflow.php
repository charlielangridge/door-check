<?php

namespace App\Services;

use App\ChildCollectionState;
use App\CollectionRequestStatus;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\User;
use App\Settings\CollectionMatchingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffCollectionWorkflow
{
    public function __construct(private readonly CollectionAudit $audit, private readonly CollectionMatchingSettings $matchingSettings, private readonly CollectionDuplicateChecks $duplicates) {}

    /** @param array<string, mixed> $data */
    public function apply(CollectionRequest $request, User $staff, array $data): CollectionRequest
    {
        $result = DB::transaction(function () use ($request, $staff, $data): CollectionRequest|ValidationException {
            $root = CollectionRequest::query()->lockForUpdate()->findOrFail($request->groupId());
            $entry = $request->getKey() === $root->getKey() ? $root : CollectionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($entry->site_id === $root->site_id && $staff->can('processCollections', $root->site), 404);

            if ($data['action'] === 'discard') {
                $this->audit->record('draft_discarded', $entry, [], $staff);

                return $entry;
            }

            abort_if((int) $data['revision'] !== $root->revision, 409, 'This request has changed—review the latest details.');
            $this->require(! $entry->collection_state->isFinished(), 'action', 'Finished collections are read-only. Ask an admin to make a correction.');

            $failure = match ($data['action']) {
                'review' => $this->review($root, $entry, $data),
                'prepare' => $this->prepare($root, $entry),
                'collect' => $this->collect($root, $entry),
                'cancel' => $this->cancel($entry, $data),
                'flag_duplicate' => $this->flagDuplicate($entry, (int) $data['child_id']),
                default => throw ValidationException::withMessages(['action' => 'Choose a valid collection action.']),
            };
            if ($failure instanceof ValidationException) {
                return $failure;
            }

            $entry->save();
            $this->duplicates->refresh($entry);
            $this->audit->record('collection_'.$data['action'], $entry, [
                'state' => $entry->collection_state->value,
                'reason' => $entry->cancellation_reason?->value,
            ], $staff);

            return $entry->refresh();
        }, 3);
        throw_if($result instanceof ValidationException, $result);

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function review(CollectionRequest $root, CollectionRequest $entry, array $data): ?ValidationException
    {
        $this->require($entry->collection_state === ChildCollectionState::NeedsChecking, 'action', 'This child has already been reviewed. Refresh the request.');
        $adultId = (int) ($data['adult_id'] ?? $root->adult_id);
        $childId = (int) ($data['child_id'] ?? $entry->requested_child_id);
        $this->require($adultId > 0, 'adult_id', 'Choose an adult belonging to this venue.');
        if ($childId < 1) {
            throw ValidationException::withMessages(['child_id' => 'Choose a child belonging to this venue.']);
        }
        $this->require($root->adult_locked_at === null || $adultId === $root->adult_id, 'adult_id', 'The adult is locked after the first child is reviewed.');

        $adult = Adult::query()->lockForUpdate()->find($adultId);
        $child = Child::query()->lockForUpdate()->find($childId);
        if ($adult === null || ! $this->isVenueMember($adult, $root->site_id)) {
            throw ValidationException::withMessages(['adult_id' => 'Choose an adult belonging to this venue.']);
        }
        if ($child === null || ! $this->isVenueMember($child, $root->site_id)) {
            throw ValidationException::withMessages(['child_id' => 'Choose a child belonging to this venue.']);
        }
        if ($this->duplicates->conflicts($entry, $childId)) {
            $entry->duplicate_child_id = $childId;
            $entry->save();
            $this->duplicates->refresh($entry);

            return ValidationException::withMessages(['child_id' => 'This child appears twice in this request. Cancel the duplicate entry or select the correct child before either entry can progress.']);
        }

        $changed = ($root->adult_id !== null && $root->adult_id !== $adultId) || ($entry->requested_child_id !== null && $entry->requested_child_id !== $childId);
        $this->require(! $changed || filled($data['match_change_reason'] ?? null), 'match_change_reason', 'Explain why you are changing the automatic match.');
        $related = $this->hasRecordedRelationship($adultId, $childId);
        $this->require($related || filled($data['relationship_override_reason'] ?? null), 'relationship_override_reason', 'Explain why this adult may collect this child without a recorded relationship.');

        $missing = array_values(array_unique([...$this->unavailablePhotos($root, $adult, $child), ...($data['unavailable_photos'] ?? [])]));
        $this->require($missing === [] || filled($data['photo_verification_reason'] ?? null), 'photo_verification_reason', 'Record how identity was verified without the missing photos.');

        if ($root->adult_id !== $adultId) {
            foreach ($root->groupEntries()->whereKeyNot($root->getKey())->whereKeyNot($entry->getKey())->orderBy('id')->lockForUpdate()->get() as $sibling) {
                $sibling->adult_id = $adult->id;
                $sibling->adult_match_status = CollectionRequestStatus::Matched;
                $sibling->status = $sibling->requested_child_id !== null && $adult->children()->whereKey($sibling->requested_child_id)->exists()
                    ? CollectionRequestStatus::Matched : CollectionRequestStatus::NeedsApproval;
                $sibling->save();
            }
        }

        $root->adult_id = $adult->id;
        $root->matching_threshold ??= $this->matchingSettings->automatic_matching_threshold;
        $root->adult_match_status = CollectionRequestStatus::Matched;
        $root->adult_site_membership_changed = false;
        $root->adult_locked_at ??= now();
        if ($root !== $entry) {
            $root->status = $root->requested_child_id !== null && $adult->children()->whereKey($root->requested_child_id)->exists()
                ? CollectionRequestStatus::Matched : CollectionRequestStatus::NeedsApproval;
            $root->save();
        }

        $entry->forceFill([
            'adult_id' => $adultId,
            'matching_threshold' => $root->matching_threshold,
            'requested_child_id' => $childId,
            'adult_match_status' => CollectionRequestStatus::Matched,
            'child_match_status' => CollectionRequestStatus::Matched,
            'status' => $related ? CollectionRequestStatus::Matched : CollectionRequestStatus::NeedsApproval,
            'adult_site_membership_changed' => false,
            'child_site_membership_changed' => false,
            'duplicate_child_match' => false,
            'duplicate_child_id' => null,
            'collection_state' => ChildCollectionState::Reviewed,
            'reviewed_at' => now(),
            'relationship_override_reason' => $related ? null : $data['relationship_override_reason'],
            'match_change_reason' => $changed ? $data['match_change_reason'] : null,
            'photo_verification_reason' => $missing === [] ? null : $data['photo_verification_reason'],
            'photo_verification_notes' => $missing === [] ? null : ($data['photo_verification_notes'] ?? null),
            'unavailable_photos' => $missing,
        ]);

        return null;
    }

    private function prepare(CollectionRequest $root, CollectionRequest $entry): void
    {
        $this->require($entry->collection_state === ChildCollectionState::Reviewed, 'action', 'Confirm checks before preparing the child.');
        $this->require(! $this->duplicates->conflicts($entry, (int) $entry->requested_child_id), 'action', 'This child appears twice in this request. Resolve the duplicate before preparing either entry.');
        $child = $this->requireCurrentEligibility($root, $entry);

        $this->require(! CollectionRequest::query()->where('requested_child_id', $child->getKey())->where('collection_state', ChildCollectionState::Preparing)->exists(), 'action', 'This child is already being prepared for another request. Cancel that preparation first.');
        $day = now()->timezone(config('staff-collections.timezone'))->startOfDay();
        $previous = CollectionRequest::query()->where('site_id', $root->site_id)->where('requested_child_id', $child->getKey())
            ->where('collection_state', ChildCollectionState::Collected)->whereBetween('collected_at', [$day->utc(), $day->addDay()->utc()])->first();
        $this->require($previous === null, 'action', 'This child has already been collected today'.($previous ? ' at '.$previous->collected_at->timezone(config('staff-collections.timezone'))->format('H:i') : '').'. Ask an admin if the earlier record is incorrect.');
        $entry->collection_state = ChildCollectionState::Preparing;
        $entry->preparing_at = now();
    }

    private function requireCurrentEligibility(CollectionRequest $root, CollectionRequest $entry): Child
    {
        $adult = Adult::query()->lockForUpdate()->find($root->adult_id);
        $child = Child::query()->lockForUpdate()->find($entry->requested_child_id);
        if ($adult === null || ! $this->isVenueMember($adult, $root->site_id)) {
            throw ValidationException::withMessages(['action' => 'The adult no longer belongs to this venue. Cancel this collection and review the records.']);
        }
        if ($child === null || ! $this->isVenueMember($child, $root->site_id)) {
            throw ValidationException::withMessages(['action' => 'The child no longer belongs to this venue. Cancel this collection and review the records.']);
        }
        $this->require($this->hasRecordedRelationship($adult->id, $child->id) || filled($entry->relationship_override_reason), 'action', 'The relationship has changed since review. Cancel this collection and review the records.');
        $missing = $this->unavailablePhotos($root, $adult, $child);
        $this->require($missing === [] || ($entry->photo_verification_reason !== null && array_diff($missing, $entry->unavailable_photos ?? []) === []), 'action', 'Photos have changed since review. Cancel this collection and review identity again.');

        return $child;
    }

    private function collect(CollectionRequest $root, CollectionRequest $entry): void
    {
        $this->require($entry->collection_state === ChildCollectionState::Preparing, 'action', 'Prepare the child before marking them collected.');
        $this->require(! $this->duplicates->conflicts($entry, (int) $entry->requested_child_id), 'action', 'Resolve the duplicate child entry before handover.');
        $this->requireCurrentEligibility($root, $entry);
        $entry->collection_state = ChildCollectionState::Collected;
        $entry->collected_at = now();
        $entry->closed_at = now();
    }

    private function flagDuplicate(CollectionRequest $entry, int $childId): void
    {
        $this->require($entry->collection_state === ChildCollectionState::NeedsChecking, 'action', 'Only an unreviewed entry can be flagged as a duplicate.');
        $child = Child::query()->lockForUpdate()->find($childId);
        $this->require($child !== null && $this->isVenueMember($child, $entry->site_id), 'child_id', 'Choose a child belonging to this venue.');
        $this->require($this->duplicates->conflicts($entry, $childId), 'child_id', 'This child has no duplicate entry. Refresh and review the request.');
        if ($childId > 0) {
            $entry->duplicate_child_id = $childId;
        }
    }

    /** @param array<string, mixed> $data */
    private function cancel(CollectionRequest $entry, array $data): void
    {
        $entry->collection_state = ChildCollectionState::Cancelled;
        $entry->cancellation_reason = $data['cancellation_reason'];
        $entry->cancellation_notes = $data['cancellation_notes'] ?? null;
        $entry->cancelled_at = now();
        $entry->closed_at = now();
    }

    /** @return list<string> */
    public function unavailablePhotos(CollectionRequest $root, Adult $adult, Child $child): array
    {
        return array_keys(array_filter([
            'child' => $child->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->isEmpty(),
            'stored_adult' => $adult->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->isEmpty(),
            'current_adult' => $root->getFirstMedia('requester_photo') === null,
        ]));
    }

    public function reopen(CollectionRequest $request, User $admin, string $reason): void
    {
        abort_unless($admin->can('access admin'), 403);
        $this->require(filled(trim($reason)), 'reason', 'Explain why this collection is being reopened.');

        DB::transaction(function () use ($request, $admin, $reason): void {
            $root = CollectionRequest::query()->lockForUpdate()->findOrFail($request->groupId());
            $entry = $root->getKey() === $request->getKey() ? $root : CollectionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->require($entry->collection_state->isFinished(), 'reason', 'Only a finished collection can be reopened.');
            $previous = $entry->collection_state->value;
            $entry->forceFill([
                'collection_state' => ChildCollectionState::NeedsChecking,
                'reviewed_at' => null, 'preparing_at' => null, 'collected_at' => null, 'cancelled_at' => null, 'closed_at' => null,
                'relationship_override_reason' => null, 'match_change_reason' => null,
                'photo_verification_reason' => null, 'photo_verification_notes' => null, 'unavailable_photos' => null,
                'cancellation_reason' => null, 'cancellation_notes' => null,
            ]);

            if (! $root->groupEntries()->whereKeyNot($entry->getKey())->whereNotNull('reviewed_at')->exists()) {
                $root->adult_locked_at = null;
                if ($root !== $entry) {
                    $root->save();
                }
            }
            $entry->save();
            $this->audit->record('collection_reopened', $entry, ['previous_state' => $previous, 'reason' => $reason], $admin);
        }, 3);
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function isVenueMember(Adult|Child $person, int $siteId): bool
    {
        $name = $person instanceof Adult ? 'adult' : 'child';

        return DB::table('site_'.$name)->where('site_id', $siteId)->where($name.'_id', $person->getKey())->lockForUpdate()->first() !== null;
    }

    private function hasRecordedRelationship(int $adultId, int $childId): bool
    {
        return DB::table('adult_child')->where('adult_id', $adultId)->where('child_id', $childId)->lockForUpdate()->first() !== null;
    }
}
