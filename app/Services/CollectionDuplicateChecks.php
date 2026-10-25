<?php

namespace App\Services;

use App\ChildCollectionState;
use App\Models\CollectionRequest;
use Illuminate\Database\Eloquent\Builder;

class CollectionDuplicateChecks
{
    public function conflicts(CollectionRequest $entry, int $childId): bool
    {
        return $entry->groupEntries()->whereKeyNot($entry->getKey())
            ->where('collection_state', '!=', ChildCollectionState::Cancelled)
            ->where(fn (Builder $query): Builder => $query->where('requested_child_id', $childId)->orWhere('duplicate_child_id', $childId))->exists();
    }

    public function refresh(CollectionRequest $request): void
    {
        $entries = $request->groupEntries()->orderBy('id')->lockForUpdate()->get();
        $counts = $entries->reject(fn (CollectionRequest $entry): bool => $entry->collection_state === ChildCollectionState::Cancelled)
            ->countBy(fn (CollectionRequest $entry): int => (int) ($entry->duplicate_child_id ?? $entry->requested_child_id));
        foreach ($entries as $entry) {
            $childId = (int) ($entry->duplicate_child_id ?? $entry->requested_child_id);
            $duplicate = ! $entry->collection_state->isFinished() && $childId !== 0 && $counts->get($childId, 0) > 1;
            if ($entry->duplicate_child_match !== $duplicate) {
                $entry->duplicate_child_match = $duplicate;
                $entry->save();
            }
        }
    }
}
