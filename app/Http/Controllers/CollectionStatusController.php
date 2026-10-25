<?php

namespace App\Http\Controllers;

use App\CollectionRequestStatus;
use App\Models\CollectionRequest;
use App\Services\CollectionBrowserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CollectionStatusController extends Controller
{
    public function show(Request $request, CollectionRequest $collectionRequest, CollectionBrowserAccess $access): Response
    {
        abort_unless($access->allows($request, $collectionRequest), 404);
        $collectionRequest->load('site', 'adult');

        return Inertia::render('CollectionRequest/Status', [
            'collection' => [
                'uuid' => $collectionRequest->uuid,
                'adult_name' => $collectionRequest->requester_name_text,
                'site' => $collectionRequest->site->only(['name', 'uuid']),
                'children' => $collectionRequest->groupEntries()->orderBy('id')->get()->map(fn (CollectionRequest $entry): array => [
                    'uuid' => $entry->uuid,
                    'name' => $entry->requested_child_text,
                    'state' => $entry->collection_state->value,
                    'label' => $entry->collection_state->label(public: true),
                ]),
                'can_add_child' => $access->canAddChild($collectionRequest),
                'finished' => $collectionRequest->groupIsFinished(),
            ],
        ]);
    }

    public function store(Request $request, CollectionRequest $collectionRequest, CollectionBrowserAccess $access): RedirectResponse
    {
        abort_unless($access->allows($request, $collectionRequest), 404);
        $data = $request->validate(['requested_child_text' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($request, $collectionRequest, $access, $data): void {
            $root = CollectionRequest::query()->whereKey($collectionRequest->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($access->allows($request, $root), 404);
            if (! $access->canAddChild($root)) {
                throw ValidationException::withMessages(['requested_child_text' => 'Another child can only be added while this request is active and its matched adult is linked to multiple children at this venue.']);
            }

            $name = Str::lower(Str::squish($data['requested_child_text']));
            foreach ($root->groupEntries()->with('requestedChild')->get() as $entry) {
                $names = [$entry->requested_child_text];
                if ($entry->requestedChild !== null) {
                    $names = [...$names, $entry->requestedChild->name, ...($entry->requestedChild->also_known_as ?? [])];
                }
                foreach ($names as $existingName) {
                    if ($name === Str::lower(Str::squish($existingName))) {
                        throw ValidationException::withMessages(['requested_child_text' => 'This child is already part of this request. Use the existing child entry.']);
                    }
                }
            }

            $entry = new CollectionRequest;
            $entry->forceFill([
                'parent_request_id' => $root->getKey(),
                'site_id' => $root->site_id,
                'requested_at' => now(),
                'requester_name_text' => $root->requester_name_text,
                'requested_child_text' => $data['requested_child_text'],
                'adult_id' => $root->adult_id,
                'adult_match_status' => CollectionRequestStatus::Matched,
                'adult_match_confidence' => $root->adult_match_confidence,
                'adult_match_candidates' => $root->adult_match_candidates,
                'matching_threshold' => $root->matching_threshold,
            ])->save();
        }, 3);

        return to_route('collection-status.show', $collectionRequest);
    }
}
