<?php

namespace App\Http\Controllers;

use App\CollectionCancellationReason;
use App\CollectionRequestMatchPerson;
use App\Http\Requests\UpdateStaffCollectionRequest;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\PhotoVerificationReason;
use App\Services\CollectionAudit;
use App\Services\CollectionBoardData;
use App\Services\CollectionDuplicateChecks;
use App\Services\StaffCollectionWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;

class StaffCollectionController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $sites = $request->user()->can('process collections at any site') ? Site::query() : $request->user()->staffSites();
        $sites = $sites->orderBy('name')->get(['sites.id', 'sites.uuid', 'sites.name']);
        abort_if($sites->isEmpty(), 403, 'No staff venues are assigned to this account.');
        if ($sites->count() === 1) {
            return redirect()->route('staff.board', $sites->first()->uuid);
        }

        return Inertia::render('Staff/Sites', ['sites' => $sites->map(fn(Site $site): array => $site->only(['name', 'uuid']))]);
    }

    public function board(Request $request, Site $site, CollectionBoardData $data, CollectionAudit $audit): Response
    {
        $audit->record('staff_board_viewed', $site);

        return Inertia::render('Staff/Board', [
            'site' => [
                'name' => $site->name,
                'slug' => $site->slug,
                'logo' => $site->getFirstMediaUrl('logo', 'medium'),
            ],
            'requests' => $data->board($site->id, $request->boolean('history')),
            'history' => $request->boolean('history'),
            'is_admin' => $request->user()->can('access admin'),
            'can_choose_site' => $request->user()->can('process collections at any site') || $request->user()->staffSites()->count() > 1,
        ]);
    }

    public function show(Request $request, Site $site, CollectionRequest $collectionRequest, CollectionBoardData $data, CollectionAudit $audit): Modal
    {
        $this->authorizeVenue($request, $site, $collectionRequest);
        $audit->record('request_viewed', $collectionRequest);

        return (new Modal('Staff/Collection', [
            'collection' => $data->details($collectionRequest),
            'cancellation_reasons' => collect(CollectionCancellationReason::cases())->map(fn(CollectionCancellationReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()]),
            'photo_reasons' => collect(PhotoVerificationReason::cases())->map(fn(PhotoVerificationReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()]),
        ]))->baseRoute('staff.board', $site->uuid);
    }

    public function data(Request $request, Site $site, CollectionRequest $collectionRequest, CollectionBoardData $data): JsonResponse
    {
        $this->authorizeVenue($request, $site, $collectionRequest);

        return response()->json($data->details($collectionRequest));
    }

    public function candidates(Request $request, Site $site, CollectionRequest $collectionRequest, CollectionBoardData $data, CollectionAudit $audit): JsonResponse
    {
        $this->authorizeVenue($request, $site, $collectionRequest);
        $input = $request->validate(['person' => ['required', 'in:adult,child'], 'q' => ['required', 'string', 'min:2', 'max:255']]);
        $audit->record('candidate_search', $collectionRequest, $input);

        return response()->json(['candidates' => $data->candidates($collectionRequest, CollectionRequestMatchPerson::from($input['person']), $input['q'])]);
    }

    public function timeline(Request $request, Site $site, CollectionRequest $collectionRequest, CollectionBoardData $data): JsonResponse
    {
        $this->authorizeVenue($request, $site, $collectionRequest);
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json($data->timeline($collectionRequest, $request->has('before') ? $request->integer('before') : null));
    }

    public function checks(Request $request, Site $site, CollectionRequest $collectionRequest, StaffCollectionWorkflow $workflow, CollectionAudit $audit, CollectionDuplicateChecks $duplicates): JsonResponse
    {
        $this->authorizeVenue($request, $site, $collectionRequest);
        $input = $request->validate(['adult_id' => ['required', 'integer'], 'child_id' => ['required', 'integer']]);
        $adult = Adult::query()->whereHas('sites', fn($query) => $query->whereKey($collectionRequest->site_id))->whereKey($request->integer('adult_id'))->firstOrFail();
        $child = Child::query()->whereHas('sites', fn($query) => $query->whereKey($collectionRequest->site_id))->whereKey($request->integer('child_id'))->firstOrFail();
        $root = CollectionRequest::query()->findOrFail($collectionRequest->groupId());
        $relationship = $adult->children()->whereKey($child->getKey())->first()?->pivot?->getAttribute('relationship');
        $audit->record('draft_identity_checked', $collectionRequest, $input);

        return response()->json([
            'relationship' => $relationship,
            'unavailable_photos' => $workflow->unavailablePhotos($root, $adult, $child),
            'duplicate' => $duplicates->conflicts($collectionRequest, $child->getKey()),
        ]);
    }

    public function update(UpdateStaffCollectionRequest $request, Site $site, CollectionRequest $collectionRequest, StaffCollectionWorkflow $workflow, CollectionBoardData $data): JsonResponse
    {
        $this->authorizeVenue($request, $site, $collectionRequest);
        $workflow->apply($collectionRequest, $request->user(), $request->validated());

        return response()->json(['saved' => true, 'collection' => $data->details($collectionRequest)]);
    }

    private function authorizeVenue(Request $request, Site $site, CollectionRequest $collectionRequest): void
    {
        abort_unless($collectionRequest->site_id === $site->id && $request->user()->can('processCollections', $site), 404);
    }
}
