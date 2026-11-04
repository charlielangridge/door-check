<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCollectionRequestRequest;
use App\Models\CollectionEntry;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Services\CollectionEntryAccess;
use App\Services\CollectionRequestSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(CreateCollectionRequestRequest $request, Site $site, CollectionEntry $entry, CollectionEntryAccess $access, CollectionRequestSubmission $submission): RedirectResponse
    {
        $browserKey = $access->browserKey($request);

        $collectionRequest = $submission->submit($request, $site, $entry, $browserKey);

        return to_route('collection-status.show', $collectionRequest)
            ->withCookie(cookie('collection_browser', $browserKey, 43200, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
    }

    public function show(CollectionRequest $collectionRequest): void {}

    public function update(Request $request, CollectionRequest $collectionRequest): void {}

    public function destroy(CollectionRequest $collectionRequest): void {}
}
