<?php

namespace App\Http\Controllers;

use App\Models\CollectionEntryDisplay;
use App\Models\Site;
use App\Services\CollectionEntryAccess;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CollectionEntryDisplayController extends Controller
{
    public function show(Site $site): Response
    {
        $display = new CollectionEntryDisplay(['site_id' => $site->getKey()]);
        $display->save();

        return $this->render($site, $display);
    }

    public function resume(Request $request, Site $site, CollectionEntryDisplay $display): Response
    {
        return $this->render($site, $display, (bool) $request->session()->get('collection_request_received', false));
    }

    private function render(Site $site, CollectionEntryDisplay $display, bool $requestReceived = false): Response
    {
        return Inertia::render('CollectionRequest/Display', [
            'site' => [...$site->only(['name', 'uuid', 'slug']), 'logo' => $site->logoUrl()],
            'display' => ['uuid' => $display->uuid],
            'requestReceived' => $requestReceived,
        ]);
    }

    public function update(Request $request, Site $site, CollectionEntryDisplay $display, CollectionEntryAccess $access): JsonResponse
    {
        abort_unless($display->site_id === $site->getKey(), 404);
        $data = $request->validate(['replace' => ['sometimes', 'boolean'], 'entry' => ['nullable', 'uuid']]);
        $entry = $access->current($display, (bool) ($data['replace'] ?? false));
        $qr = null;
        if (($data['entry'] ?? null) !== $entry->uuid) {
            $url = route('collection-request.create', ['site' => $site->slug, 'entry' => $entry->uuid]);
            $svg = (new Writer(new ImageRenderer(new RendererStyle(512, 4), new SvgImageBackEnd)))->writeString($url);
            $qr = 'data:image/svg+xml;base64,'.base64_encode($svg);
        }

        return response()->json([
            'entry' => $entry->uuid,
            'qr' => $qr,
            'remaining_ms' => max(0, (int) now()->diffInMilliseconds($entry->token_expires_at, false)),
        ]);
    }
}
