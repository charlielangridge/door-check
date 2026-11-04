<?php

namespace App\Services;

use App\Http\Requests\CreateCollectionRequestRequest;
use App\Models\CollectionEntry;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class CollectionRequestSubmission
{
    public function __construct(private readonly CollectionEntryAccess $access) {}

    public function submit(CreateCollectionRequestRequest $request, Site $site, CollectionEntry $entry, string $entryKey, bool $allowStatusAccess = true): CollectionRequest
    {
        return DB::transaction(function () use ($request, $site, $entry, $entryKey, $allowStatusAccess): CollectionRequest {
            $entry = CollectionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($this->access->allowsKey($site, $entry, $entryKey), 403);
            if ($entry->collection_request_id !== null) {
                return $entry->collectionRequest;
            }

            $collectionRequest = $site->collectionRequests()->make([
                ...$request->safe()->only([
                    'requester_name_text',
                    'requested_child_text',
                ]),
                'requested_at' => now(),
            ]);
            $collectionRequest->browser_key_hash = $allowStatusAccess ? hash('sha256', $entryKey) : null;
            $collectionRequest->save();

            if ($request->hasFile('photo')) {
                $collectionRequest->addMediaFromRequest('photo')
                    ->usingFileName('requester.'.$request->file('photo')->extension())
                    ->toMediaCollection('requester_photo');
            }

            $entry->collection_request_id = $collectionRequest->getKey();
            $entry->save();

            return $collectionRequest;
        }, 3);
    }
}
