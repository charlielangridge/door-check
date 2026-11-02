<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveParentRegistrationRequest;
use App\Models\ParentRegistration;
use App\Services\AdultPortalAccess;
use App\Services\ParentDocuments;
use App\Services\ParentRegistrationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ParentRegistrationController extends Controller
{
    public function show(Request $request, ParentRegistration $registration, ParentDocuments $documents): Response
    {
        abort_unless(hash_equals($registration->access_key, (string) $request->query('key')), 410, 'This invitation has been replaced. Use the newest email from the nursery.');
        $verified = $registration->canEdit() && $request->session()->get('parent_registration.'.$registration->uuid) === $registration->access_key;
        $payload = null;
        if ($verified) {
            $payload = Arr::except($registration->payload ?? [], ['photo_path']);
            $payload['name'] ??= $registration->child_name;
            $payload['date_of_birth'] = $registration->child_date_of_birth->toDateString();
            $payload['adults'] = array_map(fn (array $adult): array => Arr::except($adult, ['access_id', 'photo_path', 'is_manager', 'authorised_to_collect']), $payload['adults'] ?? [[
                'key' => (string) Str::uuid(), 'name' => '', 'date_of_birth' => '', 'phone' => '', 'email' => $registration->email,
                'relationship' => 'Parent', 'also_known_as' => [], 'document_responsible' => true,
            ]]);
        }

        return Inertia::render('Parents/Registration', [
            'uuid' => $registration->uuid, 'signedQuery' => $request->query(), 'verified' => $verified,
            'closed' => ! $registration->canEdit(), 'submitted' => in_array($registration->status, ['submitted', 'approved'], true),
            'payload' => $payload, 'siteName' => $verified ? $registration->site->name : null,
            'correctionMessage' => $verified ? $registration->correction_message : null,
            'documents' => $verified ? $documents->display($registration->site) : [],
            'saved' => $request->session()->get('registration_saved', false),
            'adultPageUrl' => $request->session()->get('adult_page_url'),
        ]);
    }

    public function store(SaveParentRegistrationRequest $request, ParentRegistration $registration, ParentRegistrationWorkflow $workflow, AdultPortalAccess $portal): RedirectResponse
    {
        if ($request->validated('action') === 'verify') {
            $workflow->verify($request, $registration, $request->validated('date_of_birth'));

            return back();
        }
        $access = $workflow->save($request, $registration);

        return back()->with('registration_saved', true)->with('adult_page_url', $access ? $portal->returnUrl($access) : null);
    }
}
