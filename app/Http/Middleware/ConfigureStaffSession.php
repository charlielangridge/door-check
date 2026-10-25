<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureStaffSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('staff', 'staff/*', 'login', 'two-factor-challenge', 'passkeys/login') || $request->hasCookie('staff_kiosk')) {
            config(['session.lifetime' => config('staff-collections.session_minutes')]);
        }

        return $next($request);
    }
}
