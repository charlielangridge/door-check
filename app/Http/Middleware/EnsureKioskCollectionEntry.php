<?php

namespace App\Http\Middleware;

use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use App\Models\Site;
use App\Services\CollectionEntryAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKioskCollectionEntry
{
    public function __construct(private readonly CollectionEntryAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        $display = $request->route('display');
        $entry = $request->route('entry');
        abort_unless($site instanceof Site && $display instanceof CollectionEntryDisplay && $entry instanceof CollectionEntry, 404);
        $key = $this->access->kioskKey($request, $display, $entry);
        if ($key === null || ! $this->access->allowsKey($site, $entry, $key)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please start a new collection request from the QR screen.'], 403);
            }

            return to_route('collection-display.resume', ['site' => $site->slug, 'display' => $display->uuid]);
        }

        return $next($request);
    }
}
