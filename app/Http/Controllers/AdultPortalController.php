<?php

namespace App\Http\Controllers;

use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Services\AdultPortalAccess;
use App\Services\ParentDetailsUpdates;
use App\Services\ParentDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdultPortalController extends Controller
{
    public function index(Request $request, AdultPortalAccess $portal, ParentDetailsUpdates $updates, ParentDocuments $documents): Response
    {
        $access = $portal->current($request);
        $sites = $access->adult?->sites()->wherePivot('access_suspended', false)->get() ?? collect();

        return Inertia::render('Parents/Portal', [
            'adult' => $access->adult ? [...$access->adult->only(['id', 'name', 'email', 'phone', 'also_known_as']), 'date_of_birth' => $access->adult->date_of_birth->toDateString()] : ['id' => null, 'name' => $access->name, 'email' => $access->email, 'date_of_birth' => $access->date_of_birth->toDateString()],
            'status' => session('parent_status'), 'returnUrl' => $portal->returnUrl($access),
            'sessionExpiresAt' => now()->addMinutes(30)->timestamp,
            'sites' => $sites->map(fn (Site $site): array => [
                'id' => $site->id, 'name' => $site->name, 'slug' => $site->slug,
                'canAcceptDocuments' => $documents->mayAccept($access, $site),
                'documents' => $documents->mayAccept($access, $site) ? $documents->display($site, $access) : [],
                'children' => $site->children()->whereHas('adults', fn ($query) => $query->whereKey($access->adult_id))
                    ->whereNotIn('children.id', ChildAdultSite::query()->where('site_id', $site->id)->where('adult_id', $access->adult_id)->where('is_linked', false)->select('child_id'))
                    ->with(['adults' => fn ($query) => $query->whereHas('sites', fn ($query) => $query->whereKey($site->id))
                        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('child_adult_sites as exclusions')->where('exclusions.site_id', $site->id)->where('exclusions.is_linked', false)
                            ->whereColumn('exclusions.adult_id', 'adult_child.adult_id')->whereColumn('exclusions.child_id', 'adult_child.child_id')), 'adults.media', 'media'])->orderBy('name')->get()->map(function (Child $child) use ($access, $site, $updates): array {
                                $manager = $updates->canManage($access, $site, $child);
                                $ownRelationship = $child->adults->firstWhere('id', $access->adult_id);

                                return [
                                    'id' => $child->id, 'name' => $child->name, 'photo_id' => $child->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->sortBy('id')->last()?->id,
                                    'is_active' => $child->getRelation('pivot')->getAttribute('is_active'), 'can_manage' => $manager,
                                    'relationship' => $ownRelationship?->getRelation('pivot')->getAttribute('relationship'), 'authorised_to_collect' => $ownRelationship?->getRelation('pivot')->getAttribute('authorised_to_collect'),
                                    ...($manager ? [
                                        'date_of_birth' => $child->date_of_birth->toDateString(), 'also_known_as' => $child->also_known_as ?? [],
                                        'adults' => $child->adults->map(fn (Adult $adult): array => [
                                            ...$adult->only(['id', 'name', 'email', 'phone', 'also_known_as']),
                                            'photo_id' => $adult->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->sortBy('id')->last()?->id,
                                            'relationship' => $adult->getRelation('pivot')->getAttribute('relationship'), 'authorised_to_collect' => $adult->getRelation('pivot')->getAttribute('authorised_to_collect'),
                                            ...($adult->id === $access->adult_id ? ['date_of_birth' => $adult->date_of_birth->toDateString()] : []),
                                        ])->values(),
                                    ] : []),
                                ];
                            })->values(),
            ])->values(),
            'registrations' => ParentRegistration::query()->where('adult_access_id', $access->id)
                ->when($access->adult_id !== null, fn ($query) => $query->whereNotIn('site_id', DB::table('site_adult')->where('adult_id', $access->adult_id)->where('access_suspended', true)->select('site_id')))
                ->with('site')->orderByDesc('id')->get()->map(fn (ParentRegistration $registration): array => [
                    'uuid' => $registration->uuid, 'child_name' => $registration->child_name, 'site_name' => $registration->site->name,
                    'status' => $registration->status, 'correction_message' => $registration->correction_message,
                    'submitted_details' => $registration->status !== 'approved' && $registration->payload ? [
                        'name' => $registration->payload['name'] ?? $registration->child_name, 'date_of_birth' => $registration->child_date_of_birth->toDateString(),
                        'adults' => array_map(fn (array $adult): array => Arr::except($adult, ['date_of_birth', 'access_id', 'photo_path']), $registration->payload['adults'] ?? []),
                    ] : null,
                ]),
            'updates' => ParentUpdate::query()->where('adult_access_id', $access->id)->whereIn('site_id', $sites->pluck('id')->all())->orderByDesc('id')->get()->map(fn (ParentUpdate $update): array => [
                'id' => $update->id, 'kind' => $update->kind, 'status' => $update->status, 'message' => $update->review_message,
                'payload' => $this->visibleUpdatePayload($update, $access, $updates),
            ]),
        ]);
    }

    public function store(Request $request, Site $site, AdultPortalAccess $portal, ParentDetailsUpdates $updates): RedirectResponse
    {
        $updates->propose($request, $portal->current($request), $site);

        return back()->with('parent_status', 'Your proposed update is awaiting staff review. Approved details remain in use.');
    }

    /** @return array<string, mixed> */
    private function visibleUpdatePayload(ParentUpdate $update, AdultAccess $access, ParentDetailsUpdates $rights): array
    {
        if ($update->kind === 'adult' && $update->adult_id === $access->adult_id) {
            return Arr::except($update->payload, ['photo_path']);
        }
        $child = $update->child_id ? Child::query()->find($update->child_id) : null;
        $site = Site::query()->findOrFail($update->site_id);
        if (! $child || ! $rights->canManage($access, $site, $child)) {
            return [];
        }
        if ($update->adult_id !== null && (! $site->adults()->whereKey($update->adult_id)->exists()
            || ! $child->adults()->whereKey($update->adult_id)->exists()
            || ChildAdultSite::query()->where('site_id', $site->id)->where('child_id', $child->id)->where('adult_id', $update->adult_id)->where('is_linked', false)->exists())) {
            return [];
        }

        return Arr::except($update->payload, ['photo_path', ...($update->kind !== 'child' ? ['date_of_birth'] : [])]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('adult_portal');
        $request->session()->regenerate();

        return to_route('parent-action.complete');
    }
}
