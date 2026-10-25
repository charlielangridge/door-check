<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class StaffLoginResponse extends LoginResponse
{
    public function toResponse(mixed $request): Response
    {
        if ($request->user()?->can('access staff') && ! $request->user()->can('access admin')) {
            return $request->wantsJson() ? response()->json(['two_factor' => false]) : redirect()->route('staff.board');
        }

        return parent::toResponse($request);
    }
}
