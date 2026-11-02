<?php

namespace App\Services;

use App\Models\AdultAccess;
use App\Models\ChildAdultSite;
use App\Models\DocumentAcceptance;
use App\Models\DocumentVersion;
use App\Models\ParentRegistration;
use App\Models\Site;
use App\Models\SiteDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParentDocuments
{
    public function __construct(private readonly AdultPortalAccess $portal, private readonly CollectionAudit $audit) {}

    /** @return Collection<int, DocumentVersion> */
    public function current(Site $site, bool $requiredOnly = false): Collection
    {
        return DocumentVersion::query()->whereIn('id', SiteDocument::query()->where('site_id', $site->id)
            ->where('is_archived', false)->select('current_version_id'))
            ->when($requiredOnly, fn ($query) => $query->where('requires_acceptance', true))->orderBy('id')->get();
    }

    /** @return list<array<string, mixed>> */
    public function display(Site $site, ?AdultAccess $access = null): array
    {
        $accepted = $access ? DocumentAcceptance::query()->where('adult_access_id', $access->id)->pluck('document_version_id')->all() : [];

        return array_values($this->current($site)->map(fn (DocumentVersion $version): array => [
            'id' => $version->id, 'document_id' => $version->site_document_id, 'number' => $version->number,
            'title' => $version->title, 'markdown' => $version->markdown,
            'html' => $version->markdown ? Str::markdown($version->markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
            'has_pdf' => $version->pdf_path !== null, 'required' => $version->requires_acceptance,
            'accepted' => in_array($version->id, $accepted, true),
        ])->all());
    }

    /** @param list<int> $versionIds */
    public function accept(AdultAccess $access, Site $site, array $versionIds): void
    {
        $required = $this->current($site, true)->modelKeys();
        $accepted = DocumentAcceptance::query()->where('adult_access_id', $access->id)->pluck('document_version_id')->all();
        if (array_diff($versionIds, $required) !== [] || array_diff($required, [...$versionIds, ...$accepted]) !== []) {
            throw ValidationException::withMessages(['documents' => 'The required documents have changed or are not all accepted. Review and accept the latest versions before continuing.']);
        }
        foreach ($versionIds as $versionId) {
            DocumentAcceptance::query()->firstOrCreate(['adult_access_id' => $access->id, 'document_version_id' => $versionId], [
                'actor_name' => $access->adult->name ?? $access->name, 'actor_email' => $access->email, 'accepted_at' => now(),
                'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 1023),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function outstanding(ParentRegistration $registration): array
    {
        $required = $this->current($registration->site, true);
        $participants = $registration->payload['adults'] ?? [];
        $acceptances = DocumentAcceptance::query()->whereIn('adult_access_id', array_column($participants, 'access_id'))->get()->groupBy('adult_access_id');
        $outstanding = [];
        foreach ($participants as $adult) {
            if (! ($adult['document_responsible'] ?? false)) {
                continue;
            }
            $accepted = $acceptances->get($adult['access_id'] ?? 0, collect())->pluck('document_version_id')->all();
            $missing = $required->whereNotIn('id', $accepted)->pluck('title')->all();
            if ($missing !== []) {
                $outstanding[] = ['name' => $adult['name'], 'email' => $adult['email'], 'access_id' => $adult['access_id'], 'documents' => $missing];
            }
        }

        return $outstanding;
    }

    /** @param array<string, mixed> $data */
    public function publish(Site $site, User $staff, array $data, ?UploadedFile $pdf = null): DocumentVersion
    {
        $stored = $pdf?->store('site-documents', 'local');
        try {
            return DB::transaction(function () use ($site, $staff, $data, $stored): DocumentVersion {
                Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                $document = isset($data['document_id'])
                    ? SiteDocument::query()->where('site_id', $site->id)->whereKey($data['document_id'])->lockForUpdate()->firstOrFail()
                    : SiteDocument::query()->create(['site_id' => $site->id]);
                $pdfPath = $stored ?: (($data['remove_pdf'] ?? false) ? null : $document->currentVersion?->pdf_path);
                if (blank($data['markdown'] ?? null) && ! $pdfPath) {
                    throw ValidationException::withMessages(['markdown' => 'Add Markdown content, a PDF, or both.']);
                }
                $version = DocumentVersion::query()->create([
                    'site_document_id' => $document->id, 'number' => ($document->currentVersion->number ?? 0) + 1,
                    'title' => $data['title'], 'markdown' => $data['markdown'] ?? null, 'pdf_path' => $pdfPath,
                    'requires_acceptance' => $data['requires_acceptance'], 'published_by' => $staff->id,
                ]);
                $document->update(['current_version_id' => $version->id, 'is_archived' => false]);
                $this->audit->record('site_document_published', $document, ['site_id' => $site->id, 'version_id' => $version->id], $staff);

                return $version;
            });
        } catch (\Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }
    }

    /** @return Collection<int, AdultAccess> */
    public function recipients(Site $site): Collection
    {
        return AdultAccess::query()->whereIn('adult_id', ChildAdultSite::query()->where('site_id', $site->id)
            ->where('document_responsible', true)->where('is_linked', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('adult_child')->whereColumn('adult_child.adult_id', 'child_adult_sites.adult_id')->whereColumn('adult_child.child_id', 'child_adult_sites.child_id'))
            ->whereIn('child_id', $site->children()->wherePivot('is_active', true)->wherePivot('is_approved', true)->select('children.id'))
            ->select('adult_id'))->whereHas('adult.sites', fn ($query) => $query->whereKey($site->id)->where('site_adult.access_suspended', false))
            ->orderBy('id')->get();
    }

    public function sendOutstanding(Site $site): int
    {
        $required = $this->current($site, true)->modelKeys();
        $count = 0;
        foreach ($this->recipients($site) as $access) {
            $accepted = DocumentAcceptance::query()->where('adult_access_id', $access->id)->pluck('document_version_id')->all();
            if (array_diff($required, $accepted) !== []) {
                $this->portal->sendLink($access, 'documents', $site->id);
                $count++;
            }
        }

        return $count;
    }

    public function mayAccept(AdultAccess $access, Site $site): bool
    {
        if ($access->adult_id && $site->adults()->whereKey($access->adult_id)->wherePivot('access_suspended', true)->exists()) {
            return false;
        }
        if ($this->recipients($site)->contains('id', $access->id)) {
            return true;
        }

        return ParentRegistration::query()->where('site_id', $site->id)->whereIn('status', ['submitted', 'corrections'])
            ->get()->contains(function (ParentRegistration $registration) use ($access): bool {
                foreach (($registration->payload['adults'] ?? []) as $adult) {
                    if (($adult['access_id'] ?? null) === $access->id && ($adult['document_responsible'] ?? false)) {
                        return true;
                    }
                }

                return false;
            });
    }
}
