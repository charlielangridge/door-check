<?php

namespace App\Http\Middleware;

use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\CollectionAudit;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditCollectionActivity
{
    public function __construct(private readonly CollectionAudit $audit) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $subject = $request->route('collectionRequest') ?? $request->route('site');
            if (! $subject instanceof CollectionRequest && ! $subject instanceof Site) {
                $subject = $request->user();
            }
            if ($subject instanceof CollectionRequest || $subject instanceof User || $subject instanceof Site) {
                $denied = $exception instanceof AuthorizationException || $exception instanceof AuthenticationException
                    || ($exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [401, 403, 404], true));
                $this->audit->record($denied ? 'action_denied' : 'action_failed', $subject, ['route' => $request->route()?->getName(), 'exception' => $exception::class]);
            }

            throw $exception;
        }

        $subject = $request->route('collectionRequest') ?? $request->route('site') ?? $request->user();
        if ($response->getStatusCode() >= 400 && ($subject instanceof User || $subject instanceof Site || $subject instanceof CollectionRequest)) {
            $this->audit->record(in_array($response->getStatusCode(), [401, 403, 404], true) ? 'action_denied' : 'action_failed', $subject, ['route' => $request->route()?->getName(), 'status' => $response->getStatusCode()]);
        } elseif ($request->is('admin', 'admin/*') && $request->isMethod('GET') && $request->user() instanceof User && $response->getStatusCode() < 400) {
            $this->audit->record('admin_viewed', $request->user(), ['route' => $request->route()?->getName()]);
        }

        if ($request->is('staff', 'staff/*', 'collection-status/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
