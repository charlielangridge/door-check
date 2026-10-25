<?php

namespace App\Http\Controllers;

use App\Models\Adult;
use App\Models\AuditedMedia;
use App\Models\Child;
use App\Models\CollectionRequest;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffMediaController extends Controller
{
    public function show(Request $request, Site $site, AuditedMedia $media): StreamedResponse
    {
        $siteId = $site->id;
        $allowed = match ($media->model_type) {
            Adult::class, Child::class => $media->model()->whereHas('sites', fn ($query) => $query->whereKey($siteId))->exists(),
            CollectionRequest::class => $media->model()->where('site_id', $siteId)->exists(),
            Site::class => (int) $media->model_id === $siteId,
            default => false,
        };
        abort_unless($allowed, 404);

        return $this->streamMedia($media);
    }

    public function showForAdmin(Request $request, AuditedMedia $media): StreamedResponse
    {
        abort_unless($request->user()?->can('access admin'), 403);

        return $this->streamMedia($media);
    }

    private function streamMedia(AuditedMedia $media): StreamedResponse
    {
        $conversion = $media->hasGeneratedConversion('medium') ? 'medium' : '';
        $disk = Storage::disk($conversion !== '' ? ($media->conversions_disk ?: $media->disk) : $media->disk);
        $path = $media->getPathRelativeToRoot($conversion);
        if (! $disk->exists($path) && $conversion !== '') {
            $disk = Storage::disk($media->disk);
            $path = $media->getPathRelativeToRoot();
        }
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
