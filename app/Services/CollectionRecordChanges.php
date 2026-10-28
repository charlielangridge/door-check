<?php

namespace App\Services;

use App\ChildCollectionState;
use App\Events\CollectionBoardChanged;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;

class CollectionRecordChanges
{
    public function changed(Model $record): void
    {
        $query = CollectionRequest::query()->whereNotIn('collection_state', [ChildCollectionState::Collected->value, ChildCollectionState::Cancelled->value]);
        match (true) {
            $record instanceof Adult => $query->where('adult_id', $record->getKey()),
            $record instanceof Child => $query->where('requested_child_id', $record->getKey()),
            $record instanceof Site => $query->where('site_id', $record->getKey()),
            $record instanceof CollectionRequest => $query->where(fn (Builder $query) => $query->whereKey($record->groupId())->orWhere('parent_request_id', $record->groupId())),
            default => null,
        };
        if (! $record instanceof Adult && ! $record instanceof Child && ! $record instanceof Site && ! $record instanceof CollectionRequest) {
            return;
        }

        $groupIds = $query->get(['id', 'parent_request_id'])->map(fn (CollectionRequest $entry): int => $entry->groupId())->unique()->all();
        foreach (CollectionRequest::query()->whereKey($groupIds)->get(['id', 'site_id', 'uuid']) as $root) {
            CollectionRequest::query()->whereKey($root->getKey())->increment('revision');
            event(new CollectionBoardChanged($root->site_id, $root->uuid, false));
        }
    }
}
