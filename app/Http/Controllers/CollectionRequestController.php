<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCollectionRequestRequest;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CollectionRequestController extends Controller
{
    public function index(): void {}

    public function create(Site $site): Response
    {
        return Inertia::render('CollectionRequest/Create', [
            'site' => [
                'name' => $site->name,
                'uuid' => $site->uuid,
                'logo' => $site->getFirstMediaUrl('logo', 'medium'),
            ],
        ]);

    }

    public function store(CreateCollectionRequestRequest $request, Site $site): RedirectResponse
    {
        $browserKey = $request->cookie('collection_browser');
        if (! is_string($browserKey) || strlen($browserKey) !== 64) {
            $browserKey = Str::random(64);
        }

        $collectionRequest = DB::transaction(function () use ($request, $site, $browserKey): CollectionRequest {
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

            return $collectionRequest;
        });

        return to_route('collection-status.show', $collectionRequest)
            ->withCookie(cookie('collection_browser', $browserKey, 43200, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
    }

    public function show(CollectionRequest $collectionRequest): void {}

    public function update(Request $request, CollectionRequest $collectionRequest): void {}

    public function destroy(CollectionRequest $collectionRequest): void {}
}
