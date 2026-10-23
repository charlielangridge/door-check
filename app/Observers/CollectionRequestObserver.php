<?php

namespace App\Observers;

use App\Jobs\IngestCollectionRequestJob;
use App\Models\CollectionRequest;

class CollectionRequestObserver
{
    /**
     * Handle the CollectionRequest "created" event.
     */
    public function created(CollectionRequest $collectionRequest): void
    {
        dispatch(new IngestCollectionRequestJob($collectionRequest->withoutRelations()))->afterCommit();
    }
}
