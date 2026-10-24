<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCollectionRequestRequest;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ],
        ]);

    }

    public function store(CreateCollectionRequestRequest $request, Site $site): RedirectResponse
    {
        DB::transaction(function () use ($request, $site): void {
            $collectionRequest = $site->collectionRequests()->create([
                ...$request->safe()->only([
                    'requester_name_text',
                    'requested_child_text',
                    'requester_latitude',
                    'requester_longitude',
                ]),
                'requested_at' => now(),
            ]);

            if ($request->hasFile('photo')) {
                $collectionRequest->addMediaFromRequest('photo')
                    ->usingFileName('requester.'.$request->file('photo')->extension())
                    ->toMediaCollection('requester_photo');
            }
        });

        return to_route('collection-request.create', $site);
    }

    public function show(CollectionRequest $collectionRequest): void {}

    public function update(Request $request, CollectionRequest $collectionRequest): void {}

    public function destroy(CollectionRequest $collectionRequest): void {}
}
