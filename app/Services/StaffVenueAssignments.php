<?php

namespace App\Services;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StaffVenueAssignments
{
    public function assign(User $staff, Site $site): bool
    {
        return DB::transaction(function () use ($staff, $site): bool {
            $current = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            if (! $current->hasRole('staff', 'web') || $current->staffSites()->whereKey($site->getKey())->exists()) {
                return false;
            }
            $current->staffSites()->attach($site);

            return true;
        }, 3);
    }

    public function unassign(User $staff, Site $site): bool
    {
        return DB::transaction(function () use ($staff, $site): bool {
            $current = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();

            return $current->staffSites()->detach($site) > 0;
        }, 3);
    }
}
