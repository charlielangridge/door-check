<?php

namespace App\Http\Controllers;

use App\Models\Adult;
use App\Models\AdultAccessLink;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\DocumentVersion;
use App\Models\ParentRegistration;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Services\AdultPortalAccess;
use App\Services\ParentDetailsUpdates;
use App\Services\ParentDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParentMediaController extends Controller
{
    public function document(Request $request, DocumentVersion $version, AdultPortalAccess $portal, ParentDocuments $documents): StreamedResponse
    {
        $site = Site::query()->findOrFail($version->document->site_id);
        $allowed = $request->user()?->can('processCollections', $site) ?? false;
        if (! $allowed && $request->session()->has('adult_portal')) {
            $access = $portal->current($request);
            $allowed = $documents->mayAccept($access, $site);
        }
        if (! $allowed) {
            foreach ($request->session()->get('parent_registration', []) as $uuid => $key) {
                $registration = ParentRegistration::query()->where('uuid', $uuid)->where('site_id', $site->id)->first();
                if ($registration && $registration->canEdit() && hash_equals($registration->access_key, $key)) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (! $allowed) {
            foreach ($request->session()->get('adult_document_links', []) as $uuid => $verified) {
                $link = AdultAccessLink::query()->where('uuid', $uuid)->where('site_id', $site->id)->first();
                if ($verified && $link?->isUsable() && $documents->mayAccept($link->access, $site)) {
                    $allowed = true;
                    break;
                }
            }
        }
        abort_unless($allowed && $version->pdf_path, 404);
        if (! $request->user()?->can('processCollections', $site)) {
            abort_unless($version->document->current_version_id === $version->id && ! $version->document->is_archived, 404);
        }

        return Storage::disk('local')->response($version->pdf_path, 'document.pdf', ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function photo(Request $request, Media $media, AdultPortalAccess $portal, ParentDetailsUpdates $updates): StreamedResponse
    {
        $access = $portal->current($request);
        $person = $media->model;
        abort_unless($person instanceof Child || $person instanceof Adult, 404);
        abort_unless(in_array($media->collection_name, ['photo', 'logo'], true), 404);
        $allowed = $person instanceof Adult && $person->id === $access->adult_id;
        if (! $allowed && $access->adult) {
            foreach ($access->adult->sites()->wherePivot('access_suspended', false)->get() as $site) {
                if ($person instanceof Child) {
                    $allowed = $site->children()->whereKey($person->id)->whereHas('adults', fn ($query) => $query->whereKey($access->adult_id))->exists();
                    $allowed = $allowed && ! ChildAdultSite::query()->where('site_id', $site->id)->where('child_id', $person->id)->where('adult_id', $access->adult_id)->where('is_linked', false)->exists();
                } elseif ($site->adults()->whereKey($person->id)->exists()) {
                    $allowed = $site->children()->whereHas('adults', fn ($query) => $query->whereKey($person->id))->get()
                        ->contains(fn (Child $child): bool => $updates->canManage($access, $site, $child)
                            && ! ChildAdultSite::query()->where('site_id', $site->id)->where('child_id', $child->id)->where('adult_id', $person->id)->where('is_linked', false)->exists());
                }
                if ($allowed) {
                    break;
                }
            }
        }
        abort_unless($allowed, 404);

        return Storage::disk($media->disk)->response($media->getPathRelativeToRoot(), null, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function registrationPhoto(Request $request, Site $site, ParentRegistration $registration, string $slot): StreamedResponse
    {
        abort_unless($registration->site_id === $site->id && $request->user()->can('processCollections', $site), 404);
        $payload = $registration->payload ?? [];
        $path = $slot === 'child' ? ($payload['photo_path'] ?? null) : collect($payload['adults'] ?? [])->firstWhere('key', $slot)['photo_path'] ?? null;
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function updatePhoto(Request $request, Site $site, ParentUpdate $update): StreamedResponse
    {
        abort_unless($update->site_id === $site->id && $request->user()->can('approveParentRegistrations', $site), 404);
        abort_unless($path = $update->payload['photo_path'] ?? null, 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
