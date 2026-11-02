<?php

namespace App\Http\Controllers;

use App\Models\DocumentVersion;
use App\Models\Site;
use App\Models\SiteDocument;
use App\Services\ParentDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SiteDocumentController extends Controller
{
    public function index(Request $request, Site $site, ParentDocuments $documents): Response
    {
        abort_unless($request->user()->can('manageDocuments', $site), 403);

        return Inertia::render('Staff/Documents/Index', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'slug' => $site->slug, 'logo' => $site->logoUrl()],
            'documents' => $documents->display($site), 'recipientCount' => $documents->recipients($site)->count(),
            'status' => session('document_status'),
            'history' => DocumentVersion::query()->whereIn('site_document_id', SiteDocument::query()->where('site_id', $site->id)->select('id'))
                ->with('acceptances')->orderByDesc('id')->get()->map(fn ($version): array => [
                    'id' => $version->id, 'title' => $version->title, 'number' => $version->number,
                    'has_pdf' => $version->pdf_path !== null, 'markdown' => $version->markdown,
                    'acceptances' => $version->acceptances->map(fn ($acceptance): array => $acceptance->only(['actor_name', 'actor_email', 'accepted_at']))->values(),
                ]),
        ]);
    }

    public function store(Request $request, Site $site, ParentDocuments $documents): RedirectResponse
    {
        abort_unless($request->user()->can('manageDocuments', $site), 403);
        $data = $request->validate([
            'action' => ['required', 'in:publish,send,archive'],
            'document_id' => ['nullable', 'integer'],
            'title' => ['required_if:action,publish', 'nullable', 'string', 'max:255'],
            'markdown' => ['nullable', 'string', 'max:200000'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'remove_pdf' => ['sometimes', 'boolean'],
            'requires_acceptance' => ['required_if:action,publish', 'boolean'],
        ]);
        if ($data['action'] === 'send') {
            $count = $documents->sendOutstanding($site);

            return back()->with('document_status', "Document requests queued for $count adults.");
        }
        if ($data['action'] === 'archive') {
            DB::transaction(function () use ($site, $data): void {
                Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                SiteDocument::query()->where('site_id', $site->id)->whereKey($data['document_id'])->firstOrFail()->update(['is_archived' => true]);
            });

            return back()->with('document_status', 'Document archived. Previous versions and acceptances are retained.');
        }
        $documents->publish($site, $request->user(), $data, $request->file('pdf'));

        return back()->with('document_status', 'New document version published. You can send requests to adults with outstanding acceptance.');
    }
}
