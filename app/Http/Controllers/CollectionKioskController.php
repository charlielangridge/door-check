<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCollectionRequestRequest;
use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use App\Models\Site;
use App\Services\CollectionEntryAccess;
use App\Services\CollectionRequestSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CollectionKioskController extends Controller
{
    public function start(Request $request, Site $site, CollectionEntryDisplay $display, CollectionEntryAccess $access): RedirectResponse
    {
        $entry = $access->startKiosk($request, $site, $display);
        Inertia::clearHistory();

        return to_route('collection-kiosk.create', ['site' => $site->slug, 'display' => $display->uuid, 'entry' => $entry->uuid]);
    }

    public function create(Site $site, CollectionEntryDisplay $display, CollectionEntry $entry): Response|RedirectResponse
    {
        if ($entry->collection_request_id !== null) {
            return to_route('collection-display.resume', ['site' => $site->slug, 'display' => $display->uuid]);
        }

        return Inertia::render('CollectionRequest/Create', [
            'site' => [...$site->only(['name', 'uuid', 'slug']), 'logo' => $site->logoUrl()],
            'entry' => ['uuid' => $entry->uuid, 'remaining_ms' => max(0, (int) now()->diffInMilliseconds($entry->form_expires_at, false))],
            'kiosk' => ['display' => $display->uuid],
        ]);
    }

    public function store(CreateCollectionRequestRequest $request, Site $site, CollectionEntryDisplay $display, CollectionEntry $entry, CollectionEntryAccess $access, CollectionRequestSubmission $submission): RedirectResponse
    {
        $key = $access->kioskKey($request, $display, $entry);
        abort_if($key === null, 403);
        $submission->submit($request, $site, $entry, $key, allowStatusAccess: false);
        Inertia::clearHistory();

        return to_route('collection-display.resume', ['site' => $site->slug, 'display' => $display->uuid])
            ->with('collection_request_received', true);
    }
}
