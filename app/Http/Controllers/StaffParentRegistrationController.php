<?php

namespace App\Http\Controllers;

use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\DocumentAcceptance;
use App\Models\DocumentVersion;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Services\AdultPortalAccess;
use App\Services\CollectionAudit;
use App\Services\ParentDetailsUpdates;
use App\Services\ParentDocuments;
use App\Services\ParentRegistrationWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffParentRegistrationController extends Controller
{
    public function index(Request $request, Site $site, ParentRegistrationWorkflow $workflow, ParentDocuments $documents): Response
    {
        $members = $site->adults()->get()->keyBy('id');
        $rightsByPerson = ChildAdultSite::query()->where('site_id', $site->id)->get()->keyBy(fn (ChildAdultSite $rights): string => $rights->child_id.':'.$rights->adult_id);
        $profiles = AdultAccess::query()->whereIn('adult_id', $members->modelKeys())->get()->keyBy('adult_id');
        $currentDocuments = $documents->current($site, true);
        $accepted = DocumentAcceptance::query()->whereIn('adult_access_id', $profiles->pluck('id')->all())->get()->groupBy('adult_access_id');

        return Inertia::render('Staff/Registrations/Index', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'slug' => $site->slug, 'logo' => $site->getFirstMediaUrl('logo', 'medium')],
            'canApprove' => $request->user()->can('approveParentRegistrations', $site),
            'status' => session('registration_status'),
            'registrations' => ParentRegistration::query()->where('site_id', $site->id)->with('site')->latest('id')->get()->map(fn (ParentRegistration $registration): array => [
                ...$registration->only(['id', 'uuid', 'child_name', 'email', 'status', 'revision', 'failed_attempts', 'expires_at', 'correction_message']),
                'child_date_of_birth' => $registration->child_date_of_birth->toDateString(),
                'payload' => $registration->payload ? [
                    ...Arr::except($registration->payload, ['photo_path']),
                    'has_photo' => filled($registration->payload['photo_path'] ?? null),
                    'adults' => array_map(fn (array $adult): array => [...Arr::except($adult, ['photo_path']), 'has_photo' => filled($adult['photo_path'] ?? null)], $registration->payload['adults'] ?? []),
                ] : null,
                'duplicates' => $registration->status === 'submitted' ? $workflow->duplicates($registration) : null,
                'outstanding' => $registration->status === 'submitted' ? $documents->outstanding($registration) : [],
            ]),
            'updates' => $request->user()->can('approveParentRegistrations', $site)
                ? ParentUpdate::query()->where('site_id', $site->id)->where('status', 'pending')->with('access')->orderBy('id')->get()->map(fn (ParentUpdate $update): array => [
                    ...$update->only(['id', 'kind', 'status', 'adult_id', 'child_id', 'original', 'email_verified_at']),
                    'payload' => Arr::except($update->payload, ['photo_path']),
                    'has_photo' => filled($update->payload['photo_path'] ?? null),
                    'submitted_by' => $update->access->name,
                    'adult_matches' => $update->kind === 'add_adult' ? $site->adults()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($update->payload['name']))])->whereDate('date_of_birth', $update->payload['date_of_birth'])->get(['adults.id', 'name']) : [],
                ]) : [],
            'children' => $site->children()->with('adults')->orderBy('name')->get()->map(function (Child $child) use ($members, $rightsByPerson, $profiles, $currentDocuments, $accepted): array {
                return [
                    'id' => $child->id, 'uuid' => $child->uuid, 'name' => $child->name, 'is_active' => $child->getRelation('pivot')->getAttribute('is_active'),
                    'adults' => $child->adults->whereIn('id', $members->modelKeys())->map(function (Adult $adult) use ($child, $members, $rightsByPerson, $profiles, $currentDocuments, $accepted): array {
                        $rights = $rightsByPerson->get($child->id.':'.$adult->id);
                        $profile = $profiles->get($adult->id);

                        return ['id' => $adult->id, 'name' => $adult->name, 'email' => $adult->email, 'relationship' => $adult->getRelation('pivot')->getAttribute('relationship'),
                            'authorised_to_collect' => $adult->getRelation('pivot')->getAttribute('authorised_to_collect'),
                            'is_linked' => $rights->is_linked ?? true,
                            'is_manager' => $rights->is_manager ?? false, 'document_responsible' => $rights->document_responsible ?? false,
                            'access_suspended' => $members->get($adult->id)?->getRelation('pivot')->getAttribute('access_suspended') ?? false,
                            'access_id' => $profile?->id,
                            'outstanding_documents' => $profile && $rights?->document_responsible
                                ? $this->outstandingTitles($currentDocuments, array_values($accepted->get($profile->id, collect())->pluck('document_version_id')->all())) : [],
                        ];
                    })->values()->all(),
                ];
            }),
        ]);
    }

    public function store(Request $request, Site $site, ParentRegistrationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $workflow->invite($site, $request->user(), $data);

        return back()->with('registration_status', 'Invitation queued for email delivery.');
    }

    /**
     * @param  Collection<int, DocumentVersion>  $versions
     * @param  list<int>  $acceptedIds
     * @return list<array{title: string}>
     */
    private function outstandingTitles(Collection $versions, array $acceptedIds): array
    {
        $titles = [];
        foreach ($versions as $version) {
            if (! in_array($version->id, $acceptedIds, true)) {
                $titles[] = ['title' => $version->title];
            }
        }

        return $titles;
    }

    public function update(Request $request, Site $site, ParentRegistration $registration, ParentRegistrationWorkflow $workflow, ParentDocuments $documents, AdultPortalAccess $portal): RedirectResponse
    {
        abort_unless($registration->site_id === $site->id, 404);
        $data = $request->validate([
            'action' => ['required', 'in:resend,return,review,approve,send_documents'],
            'revision' => ['required_unless:action,resend', 'integer'],
            'message' => ['required_if:action,return', 'nullable', 'string', 'max:5000'],
            'email' => ['nullable', 'email', 'max:255'], 'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_active' => ['required_if:action,approve', 'boolean'],
            'child_choice' => ['nullable', 'string', 'max:30'],
            'adult_choices' => ['sometimes', 'array'], 'adult_choices.*' => ['nullable', 'string', 'max:30'],
            'adults' => ['sometimes', 'array', 'max:20'],
            'adults.*.is_manager' => ['required', 'boolean'], 'adults.*.document_responsible' => ['required', 'boolean'],
            'adults.*.authorised_to_collect' => ['required', 'boolean'],
        ]);
        if ($data['action'] === 'resend') {
            $workflow->resend($registration, $request->user(), $data['email'] ?? null, $data['date_of_birth'] ?? null);
        } else {
            abort_unless($request->user()->can('approveParentRegistrations', $site), 403);
            match ($data['action']) {
                'return' => $workflow->returnForCorrections($registration, $request->user(), $data['message'], (int) $data['revision']),
                'review' => $workflow->reviewDetails($registration, $request->user(), $data),
                'approve' => $workflow->approve($registration, $request->user(), $data),
                'send_documents' => $this->sendDocuments($registration, $documents, $portal),
                default => abort(422),
            };
        }

        return back()->with('registration_status', 'Registration updated.');
    }

    private function sendDocuments(ParentRegistration $registration, ParentDocuments $documents, AdultPortalAccess $portal): void
    {
        abort_unless($registration->status === 'submitted', 409);
        foreach ($documents->outstanding($registration) as $adult) {
            $portal->sendLink(AdultAccess::query()->whereKey($adult['access_id'])->firstOrFail(), 'documents', $registration->site_id);
        }
    }

    public function reviewUpdate(Request $request, Site $site, ParentUpdate $update, ParentDetailsUpdates $updates): RedirectResponse
    {
        abort_unless($update->site_id === $site->id, 404);
        abort_unless($request->user()->can('approveParentRegistrations', $site), 403);
        $data = $request->validate(['action' => ['required', 'in:approve_update,reject_update'], 'message' => ['nullable', 'string', 'max:5000'], 'adult_choice' => ['nullable', 'string', 'max:30']]);
        $updates->review($update, $request->user(), $data);

        return back()->with('registration_status', 'Proposed update reviewed.');
    }

    public function membership(Request $request, Site $site, Child $child, CollectionAudit $audit, AdultPortalAccess $portal): RedirectResponse
    {
        abort_unless($site->children()->whereKey($child->id)->exists(), 404);
        $data = $request->validate([
            'action' => ['required', 'in:active,collection,rights,suspension,enable_access,association'],
            'adult_id' => ['required_unless:action,active', 'integer'],
            'value' => ['sometimes', 'boolean'], 'is_manager' => ['sometimes', 'boolean'], 'document_responsible' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $site, $child, $data, $audit, $portal): void {
            $child = Child::query()->lockForUpdate()->findOrFail($child->id);
            if ($data['action'] === 'active') {
                $child->sites()->updateExistingPivot($site->id, ['is_active' => $request->boolean('value')]);
            } else {
                $adult = $site->adults()->whereKey($data['adult_id'])->firstOrFail();
                abort_unless($child->adults()->whereKey($adult->id)->exists(), 404);
                if ($data['action'] === 'collection') {
                    $child->adults()->updateExistingPivot($adult->id, ['authorised_to_collect' => $request->boolean('value')]);
                } else {
                    abort_unless($request->user()->can('approveParentRegistrations', $site), 403);
                    if ($data['action'] === 'association') {
                        $values = ['is_linked' => $request->boolean('value')];
                        if (! $request->boolean('value')) {
                            $values += ['is_manager' => false, 'document_responsible' => false];
                        }
                        ChildAdultSite::query()->updateOrCreate(['site_id' => $site->id, 'adult_id' => $adult->id, 'child_id' => $child->id], $values);
                    } elseif ($data['action'] === 'suspension') {
                        $adult->sites()->updateExistingPivot($site->id, ['access_suspended' => $request->boolean('value')]);
                    } elseif ($data['action'] === 'rights') {
                        abort_if(ChildAdultSite::query()->where('site_id', $site->id)->where('adult_id', $adult->id)->where('child_id', $child->id)->where('is_linked', false)->exists(), 422, 'Restore the site association before granting rights.');
                        if ($request->boolean('document_responsible') && blank($adult->email)) {
                            throw ValidationException::withMessages(['adult_id' => 'Add and verify an email address before nominating this adult for documents.']);
                        }
                        if ($request->boolean('document_responsible')) {
                            $portal->forAdult($adult);
                        }
                        ChildAdultSite::query()->updateOrCreate(['site_id' => $site->id, 'adult_id' => $adult->id, 'child_id' => $child->id], [
                            'is_manager' => $request->boolean('is_manager'), 'document_responsible' => $request->boolean('document_responsible'),
                        ]);
                    } else {
                        if (blank($adult->email)) {
                            throw ValidationException::withMessages(['adult_id' => 'Record this adult’s email before enabling online access.']);
                        }
                        $portal->sendLink($portal->forAdult($adult));
                    }
                }
            }
            $audit->record('parent_membership_changed', $child, ['site_id' => $site->id, ...$data], $request->user());
        });

        return back()->with('registration_status', 'Membership updated.');
    }
}
