<?php

namespace App\Services;

use App\ChildCollectionState;
use App\CollectionRequestMatchPerson;
use App\Models\Adult;
use App\Models\Audit;
use App\Models\Child;
use App\Models\CollectionRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CollectionBoardData
{
    public function __construct(private readonly CollectionRequestCandidateFinder $finder) {}

    /** @return list<array<string, mixed>> */
    public function board(int $siteId, bool $history = false): array
    {
        $terminal = [ChildCollectionState::Collected->value, ChildCollectionState::Cancelled->value];
        $query = CollectionRequest::query()->where('site_id', $siteId)->whereNull('parent_request_id')
            ->with(['adult', 'requestedChild', 'additionalChildren.requestedChild']);

        if ($history) {
            $start = now()->timezone(config('staff-collections.timezone'))->startOfDay()->toImmutable();
            $query->whereIn('collection_state', $terminal)
                ->whereDoesntHave('additionalChildren', fn (Builder $query): Builder => $query->whereNotIn('collection_state', $terminal))
                ->where(fn (Builder $query): Builder => $query
                    ->whereBetween('closed_at', [$start->utc(), $start->addDay()->utc()])
                    ->orWhereHas('additionalChildren', fn (Builder $query): Builder => $query->whereBetween('closed_at', [$start->utc(), $start->addDay()->utc()])));
        } else {
            $query->where(fn (Builder $query): Builder => $query->whereNotIn('collection_state', $terminal)
                ->orWhereHas('additionalChildren', fn (Builder $query): Builder => $query->whereNotIn('collection_state', $terminal)));
        }

        return array_values($query->orderBy('requested_at')->orderBy('id')->get()->map(function (CollectionRequest $root): array {
            $entries = collect([$root])->concat($root->additionalChildren);

            return [
                'uuid' => $root->uuid,
                'adult_name' => $root->adult_id !== null ? $root->adult->name : $root->requester_name_text,
                'requested_at' => $root->requested_at->toIso8601String(),
                'previous_day' => $root->requested_at->timezone(config('staff-collections.timezone'))->toDateString() !== now()->timezone(config('staff-collections.timezone'))->toDateString(),
                'children' => $entries->map(fn (CollectionRequest $entry): array => [
                    'uuid' => $entry->uuid,
                    'name' => $entry->requested_child_id !== null ? $entry->requestedChild->name : $entry->requested_child_text,
                    'state' => $entry->collection_state->value,
                    'label' => $entry->collection_state->label(),
                    'needs_matching' => $root->adult_id === null || $entry->requested_child_id === null || $entry->duplicate_child_match,
                ])->values()->all(),
            ];
        })->all());
    }

    /** @return array<string, mixed> */
    public function details(CollectionRequest $request): array
    {
        $root = CollectionRequest::query()->with(['site', 'adult.media', 'media'])->findOrFail($request->groupId());
        $entries = $root->groupEntries()->with(['requestedChild.media'])->orderBy('id')->get();
        $relationships = $root->adult?->children()->pluck('relationship', 'children.id')->all() ?? [];

        return [
            'uuid' => $root->uuid,
            'revision' => $root->revision,
            'site_name' => $root->site->name,
            'site_id' => $root->site_id,
            'requested_at' => $root->requested_at->toIso8601String(),
            'submitted_adult_name' => $root->requester_name_text,
            'adult' => $root->adult ? $this->person($root->adult) : null,
            'adult_locked' => $root->adult_locked_at !== null,
            'requester_photo' => $this->photo($root->getFirstMedia('requester_photo')),
            'adult_candidates' => $this->candidates($root, CollectionRequestMatchPerson::Adult),
            'adult_match_status' => $root->adult_match_status->value,
            'adult_match_confidence' => $root->adult_match_confidence,
            'threshold' => $root->matching_threshold,
            'finished' => $root->groupIsFinished(),
            'children' => $entries->map(fn (CollectionRequest $entry): array => [
                'uuid' => $entry->uuid,
                'submitted_name' => $entry->requested_child_text,
                'child' => $entry->requestedChild ? $this->person($entry->requestedChild) : null,
                'state' => $entry->collection_state->value,
                'label' => $entry->collection_state->label(),
                'match_status' => $entry->child_match_status->value,
                'match_confidence' => $entry->child_match_confidence,
                'duplicate' => $entry->duplicate_child_match,
                'relationship' => $relationships[$entry->requested_child_id] ?? null,
                'relationship_override_reason' => $entry->relationship_override_reason,
                'photo_verification_reason' => $entry->photo_verification_reason?->value,
                'cancellation_reason' => $entry->cancellation_reason?->label(),
                'cancellation_notes' => $entry->cancellation_notes,
                'candidates' => $this->candidates($entry, CollectionRequestMatchPerson::Child),
            ])->values(),
            'timeline' => $this->timeline($root),
        ];
    }

    /** @return array{events: list<array<string, mixed>>, next_cursor: int|null} */
    public function timeline(CollectionRequest $root, ?int $before = null): array
    {
        $childIds = $root->groupEntries()->whereNotNull('requested_child_id')->pluck('requested_child_id');
        $query = Audit::query()->where(function (Builder $query) use ($root, $childIds): void {
            $query->where(fn (Builder $query): Builder => $query->where('collection_request_id', $root->groupId())->where('site_id', $root->site_id))
                ->orWhere(function (Builder $query) use ($root, $childIds): void {
                    $query->where('created_at', '>=', $root->requested_at)
                        ->where(fn (Builder $query): Builder => $query->whereNull('site_id')->orWhere('site_id', $root->site_id))
                        ->where(function (Builder $query) use ($root, $childIds): void {
                            $query->where(function (Builder $query) use ($root, $childIds): void {
                                $query->whereNotIn('event', ['relationship_attached', 'relationship_changed', 'relationship_detached'])
                                    ->where(fn (Builder $query): Builder => $query
                                        ->where(fn (Builder $query): Builder => $query->where('auditable_type', Adult::class)->where('auditable_id', $root->adult_id))
                                        ->orWhere(fn (Builder $query): Builder => $query->where('auditable_type', Child::class)->whereIn('auditable_id', $childIds)));
                            })->orWhere(fn (Builder $query): Builder => $query->whereNotNull('adult_id')->where('adult_id', $root->adult_id)->whereNull('child_id'))
                                ->orWhere(fn (Builder $query): Builder => $query->whereIn('child_id', $childIds)->whereNull('adult_id'))
                                ->orWhere(fn (Builder $query): Builder => $query->where('adult_id', $root->adult_id)->whereIn('child_id', $childIds));
                        });
                });
        });
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $audits = $query->orderByDesc('id')->limit(51)->get();

        return [
            'events' => array_values($audits->take(50)->map(fn (Audit $audit): array => [
                'id' => $audit->getKey(),
                'event' => $audit->event,
                'actor' => $audit->actor_name ?? 'System / collecting adult',
                'at' => $audit->created_at?->toIso8601String(),
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
            ])->values()->all()),
            'next_cursor' => $audits->count() > 50 ? (int) $audits[49]->getKey() : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function candidates(CollectionRequest $request, CollectionRequestMatchPerson $person, ?string $search = null): array
    {
        $snapshots = $search === null ? ($request->getAttribute($person->value.'_match_candidates') ?? []) : [];
        if ($snapshots === []) {
            $name = $search ?? $request->getAttribute($person->nameColumn());
            if (DB::getDriverName() === 'pgsql') {
                $snapshots = $this->finder->find($person, $name, $request->site_id)['candidates'];
            } else {
                $snapshots = $person->modelClass()::query()->whereHas('sites', fn (Builder $query): Builder => $query->whereKey($request->site_id))
                    ->where('name', 'like', '%'.addcslashes($name, '%_\\').'%')->orderBy('name')->orderBy('id')->limit(5)->get()->map(fn ($candidate): array => ['id' => $candidate->getKey()])->all();
            }
        }

        $people = $person->modelClass()::query()->whereHas('sites', fn (Builder $query): Builder => $query->whereKey($request->site_id))
            ->whereKey(array_column($snapshots, 'id'))->with('media')->get()->keyBy('id');
        $results = [];
        foreach ($snapshots as $snapshot) {
            $candidate = $people->get($snapshot['id']);
            if ($candidate !== null) {
                $results[] = [...$this->person($candidate), 'confidence' => $snapshot['confidence'] ?? null];
            }
            if (count($results) === 5) {
                break;
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function person(Adult|Child $person): array
    {
        return ['id' => $person->getKey(), 'name' => $person->name, 'aliases' => $person->also_known_as ?? [], 'photo' => $this->photo($person->getMedia('logo')->last())];
    }

    private function photo(?Media $media): ?string
    {
        return $media ? route(auth()->user()?->can('access admin') ? 'admin.media.show' : 'staff.media.show', $media->getKey()) : null;
    }
}
