<?php

namespace App\Observers;

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
    }

    /**
     * Handle the CollectionRequest "created" event.
     */
    public function created(CollectionRequest $collectionRequest): void
    {
        dispatch(new IngestCollectionRequestJob($collectionRequest->withoutRelations()))->afterCommit();
    }
}
