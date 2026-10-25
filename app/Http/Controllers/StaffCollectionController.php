<?php

namespace App\Http\Controllers;

use App\CollectionCancellationReason;
use App\CollectionRequestMatchPerson;
use App\Http\Requests\UpdateStaffCollectionRequest;
use App\Models\Adult;
use App\Models\Child;
use App\Models\CollectionRequest;
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
        ]);
    }

    public function show(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data, CollectionAudit $audit): Modal
    {
        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);
        $audit->record('request_viewed', $collectionRequest);

        return (new Modal('Staff/Collection', [
            'collection' => $data->details($collectionRequest),
            'cancellation_reasons' => collect(CollectionCancellationReason::cases())->map(fn (CollectionCancellationReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()]),
            'photo_reasons' => collect(PhotoVerificationReason::cases())->map(fn (PhotoVerificationReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()]),
        ]))->baseRoute('staff.board');
    }

    public function data(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data): JsonResponse
    {
        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);

        return response()->json($data->details($collectionRequest));
    }

    public function candidates(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data, CollectionAudit $audit): JsonResponse
    {
        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);
        $input = $request->validate(['person' => ['required', 'in:adult,child'], 'q' => ['required', 'string', 'min:2', 'max:255']]);
        $audit->record('candidate_search', $collectionRequest, $input);

        return response()->json(['candidates' => $data->candidates($collectionRequest, CollectionRequestMatchPerson::from($input['person']), $input['q'])]);
    }

    public function timeline(Request $request, CollectionRequest $collectionRequest, CollectionBoardData $data): JsonResponse
    {
        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json($data->timeline($collectionRequest, $request->has('before') ? $request->integer('before') : null));
    }

    public function checks(Request $request, CollectionRequest $collectionRequest, StaffCollectionWorkflow $workflow, CollectionAudit $audit, CollectionDuplicateChecks $duplicates): JsonResponse
    {
        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);
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
        abort_unless($collectionRequest->site_id === $request->user()->staff_site_id, 404);
        $workflow->apply($collectionRequest, $request->user(), $request->validated());

        return response()->json(['saved' => true, 'collection' => $data->details($collectionRequest)]);
    }
}
