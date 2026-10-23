<?php

namespace App\Jobs;

use App\CollectionRequestStatus;
use App\Models\CollectionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IngestCollectionRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly CollectionRequest $collectionRequest) {}

    public function handle(): void
    {
        $request = $this->collectionRequest->fresh();

        if (! $request || $request->status->isComplete()) {
            return;
        }

        if (in_array($request->adult_match_status, [CollectionRequestStatus::Pending, CollectionRequestStatus::Processing], true)) {
            dispatch(new MatchCollectionRequestAdultJob((int) $request->getKey()))->afterCommit();
        }

        if (in_array($request->child_match_status, [CollectionRequestStatus::Pending, CollectionRequestStatus::Processing], true)) {
            dispatch(new MatchCollectionRequestChildJob((int) $request->getKey()))->afterCommit();
        }
    }
}
