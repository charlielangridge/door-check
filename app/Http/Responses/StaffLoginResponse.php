<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class StaffLoginResponse extends LoginResponse
{
    public function toResponse(mixed $request): Response
    {
        if (($request->user()?->can('access staff') || $request->user()?->can('process collections at any site')) && ! $request->user()->can('access admin')) {
            return $request->wantsJson() ? response()->json(['two_factor' => false]) : to_route('staff.index');
        }

        return parent::toResponse($request);
    }
}
