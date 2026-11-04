<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCollectionRequestRequest;
use App\Models\CollectionEntry;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Services\CollectionEntryAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CollectionRequestController extends Controller
{
    public function index(): void {}

    public function create(Request $request, Site $site, CollectionEntryAccess $access, ?CollectionEntry $entry = null): Response|HttpResponse
    {
        $siteData = [...$site->only(['name', 'uuid', 'slug']), 'logo' => $site->logoUrl()];
        if ($entry === null) {
            return Inertia::render('CollectionRequest/ScanRequired', ['site' => $siteData]);
        }

        $browserKey = $access->browserKey($request);
        $entry = $access->claim($site, $entry, $browserKey);
        if ($entry === null) {
            return Inertia::render('CollectionRequest/ScanRequired', ['site' => $siteData]);
        }

        if ($entry->collection_request_id !== null) {
            return to_route('collection-status.show', $entry->collectionRequest);
        }

        $response = Inertia::render('CollectionRequest/Create', [
            'site' => $siteData,
            'entry' => ['uuid' => $entry->uuid, 'remaining_ms' => max(0, (int) now()->diffInMilliseconds($entry->form_expires_at, false))],
        ])->toResponse($request);
        $response->headers->setCookie(cookie('collection_browser', $browserKey, 43200, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));

        return $response;
    }

    public function store(CreateCollectionRequestRequest $request, Site $site, CollectionEntry $entry, CollectionEntryAccess $access): RedirectResponse
    {
        $browserKey = $access->browserKey($request);

        $collectionRequest = DB::transaction(function () use ($request, $site, $browserKey, $entry, $access): CollectionRequest {
            $entry = CollectionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($access->allows($request, $site, $entry), 403);
            if ($entry->collection_request_id !== null) {
                return $entry->collectionRequest;
            }

            $collectionRequest = $site->collectionRequests()->make([
                ...$request->safe()->only([
                    'requester_name_text',
                    'requested_child_text',
                    'requester_latitude',
                    'requester_longitude',
                ]),
                'requested_at' => now(),
            ]);
            $collectionRequest->browser_key_hash = hash('sha256', $browserKey);
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

        return to_route('collection-status.show', $collectionRequest)
            ->withCookie(cookie('collection_browser', $browserKey, 43200, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
    }

    public function show(CollectionRequest $collectionRequest): void {}

    public function update(Request $request, CollectionRequest $collectionRequest): void {}

    public function destroy(CollectionRequest $collectionRequest): void {}
}
