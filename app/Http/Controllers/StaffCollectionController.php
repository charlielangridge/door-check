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
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;

class StaffCollectionController extends Controller
{
    public function index(Request $request, CollectionBoardData $data): Response
    {
        return Inertia::render('Staff/Board', [
            'site' => $request->user()->staffSite()->firstOrFail()->only(['name', 'id']),
            'requests' => $data->board($request->user()->staff_site_id, $request->boolean('history')),
            'history' => $request->boolean('history'),
            'admin_site' => null,
        ]);
    }

    public function site(Request $request, Site $site, CollectionBoardData $data, CollectionAudit $audit): Response
    {
        $audit->record('staff_board_viewed', $site);

        return Inertia::render('Staff/Board', [
            'site' => $site->only(['name', 'id']),
            'requests' => $data->board($site->id, $request->boolean('history')),
            'history' => $request->boolean('history'),
            'admin_site' => $site->uuid,
        ]);
    }

    public function show(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data, CollectionAudit $audit): Modal
    {
        $this->authorizeVenue($request, $collectionRequest);
        $audit->record('request_viewed', $collectionRequest);

        return (new Modal('Staff/Collection', [
            'collection' => $data->details($collectionRequest),
            'admin_site' => $request->routeIs('admin.staff.*') ? $collectionRequest->site->uuid : null,
            'cancellation_reasons' => collect(CollectionCancellationReason::cases())->map(fn (CollectionCancellationReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()]),
            'photo_reasons' => collect(PhotoVerificationReason::cases())->map(fn (PhotoVerificationReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()]),
        ]))->baseUrl($request->routeIs('admin.staff.*') ? route('admin.staff.board', $collectionRequest->site->uuid) : route('staff.board'));
    }

    public function data(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data): JsonResponse
    {
        $this->authorizeVenue($request, $collectionRequest);

        return response()->json($data->details($collectionRequest));
    }

    public function candidates(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data, CollectionAudit $audit): JsonResponse
    {
        $this->authorizeVenue($request, $collectionRequest);
        $input = $request->validate(['person' => ['required', 'in:adult,child'], 'q' => ['required', 'string', 'min:2', 'max:255']]);
        $audit->record('candidate_search', $collectionRequest, $input);

        return response()->json(['candidates' => $data->candidates($collectionRequest, CollectionRequestMatchPerson::from($input['person']), $input['q'])]);
    }

    public function timeline(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data): JsonResponse
    {
        $this->authorizeVenue($request, $collectionRequest);
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json($data->timeline($collectionRequest, $request->has('before') ? $request->integer('before') : null));
    }

    public function checks(Request $request, CollectionRequest $collectionRequest, StaffCollectionWorkflow $workflow, CollectionAudit $audit, CollectionDuplicateChecks $duplicates): JsonResponse
    {
        $this->authorizeVenue($request, $collectionRequest);
        $input = $request->validate(['adult_id' => ['required', 'integer'], 'child_id' => ['required', 'integer']]);
        $adult = Adult::query()->whereHas('sites', fn ($query) => $query->whereKey($collectionRequest->site_id))->whereKey($request->integer('adult_id'))->firstOrFail();
        $child = Child::query()->whereHas('sites', fn ($query) => $query->whereKey($collectionRequest->site_id))->whereKey($request->integer('child_id'))->firstOrFail();
        $root = CollectionRequest::query()->findOrFail($collectionRequest->groupId());
        $relationship = $adult->children()->whereKey($child->getKey())->first()?->pivot?->getAttribute('relationship');
        $audit->record('draft_identity_checked', $collectionRequest, $input);

        return response()->json([
            'relationship' => $relationship,
            'unavailable_photos' => $workflow->unavailablePhotos($root, $adult, $child),
            'duplicate' => $duplicates->conflicts($collectionRequest, $child->getKey()),
        ]);
    }

    public function update(UpdateStaffCollectionRequest $request, CollectionRequest $collectionRequest, StaffCollectionWorkflow $workflow, CollectionBoardData $data): JsonResponse
    {
        $this->authorizeVenue($request, $collectionRequest);
        $workflow->apply($collectionRequest, $request->user(), $request->validated(), asAdmin: $request->routeIs('admin.staff.*'));

        return response()->json(['saved' => true, 'collection' => $data->details($collectionRequest)]);
    }

    private function authorizeVenue(Request $request, CollectionRequest $collectionRequest): void
    {
        if ($request->routeIs('admin.staff.*')) {
            abort_unless($request->user()?->can('access admin'), 403);

            return;
        }

        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);
    }
}
