<?php

namespace App\Http\Middleware;

use App\Services\StaffSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffSession
{
    public function __construct(private readonly StaffSession $sessions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->can('access staff') && $request->user()->staff_site_id !== null, 403);

        if (! $this->sessions->isCurrent($request)) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('login'))->with('status', 'Please sign in for today’s staff session.');
        }

        return $next($request);
    }
}
