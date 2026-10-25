<?php

namespace App\Observers;

use App\Events\CollectionBoardChanged;
use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionRequest;
use Illuminate\Validation\ValidationException;

class CollectionRequestObserver
{
    public function updating(CollectionRequest $collectionRequest): void
    {
        if ($collectionRequest->isDirty('site_id')) {
            throw ValidationException::withMessages(['site_id' => 'The site cannot be changed after a collection request is created.']);
        }

        if ($collectionRequest->isDirty('parent_request_id')) {
            throw ValidationException::withMessages(['parent_request_id' => 'A child collection cannot be moved to another request.']);
        }

        $collectionRequest->revision = $collectionRequest->revision + 1;
    }

    /**
     * Handle the CollectionRequest "created" event.
     */
    public function created(CollectionRequest $collectionRequest): void
    {
        dispatch(new IngestCollectionRequestJob($collectionRequest->withoutRelations()))->afterCommit();
    }

    public function saved(CollectionRequest $collectionRequest): void
    {
        if ($collectionRequest->parent_request_id !== null) {
            CollectionRequest::query()->whereKey($collectionRequest->parent_request_id)->increment('revision');
        }

        $uuid = $collectionRequest->parent_request_id === null ? $collectionRequest->uuid
            : CollectionRequest::query()->whereKey($collectionRequest->parent_request_id)->value('uuid');
        if ($uuid !== null) {
            event(new CollectionBoardChanged($collectionRequest->site_id, $uuid, $collectionRequest->wasRecentlyCreated));
        }
    }
}
