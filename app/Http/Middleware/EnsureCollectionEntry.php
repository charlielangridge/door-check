<?php

namespace App\Http\Middleware;

use App\Models\CollectionEntry;
use App\Models\Site;
use App\Services\CollectionEntryAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCollectionEntry
{
    public function __construct(private readonly CollectionEntryAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $entry = $request->route('entry');
        $site = $request->route('site');
        abort_unless($site instanceof Site, 404);
        if (! $entry instanceof CollectionEntry || ! $this->access->allows($request, $site, $entry)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please scan the QR code on the screen at your site'], 403);
            }

            return to_route('collection-request.create', ['site' => $site->slug]);
        }

        return $next($request);
    }
}
