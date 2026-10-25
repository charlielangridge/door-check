<?php

namespace App\Services;

use Illuminate\Http\Request;

class StaffSession
{
    public function isCurrent(Request $request): bool
    {
        $boundary = now()->timezone(config('staff-collections.timezone'))->startOfDay()->addHours((int) config('staff-collections.day_starts_at'));
        if (now()->lessThan($boundary)) {
            $boundary = $boundary->subDay();
        }

        return (int) $request->session()->get('staff_signed_in_at', 0) >= $boundary->timestamp;
    }
}
