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
            if ($current->staff_site_id !== null || ! $current->hasRole('staff', 'web')) {
                return false;
            }
            $current->staffSite()->associate($site);

            return $current->save();
        }, 3);
    }

    public function unassign(User $staff, Site $site): bool
    {
        return DB::transaction(function () use ($staff, $site): bool {
            $current = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            if ($current->staff_site_id !== $site->getKey()) {
                return false;
            }
            $current->staffSite()->dissociate();

            return $current->save();
        }, 3);
    }
}
