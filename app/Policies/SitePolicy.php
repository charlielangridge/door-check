<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Site $site): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Site $site): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Site $site): bool
    {
        return ! $site->collectionRequests()->exists() && ! $site->staffUsers()->exists();
    }

    public function processCollections(User $user, Site $site): bool
    {
        return $user->can('process collections at any site')
            || ($user->can('access staff') && $user->staffSites()->whereKey($site->getKey())->exists());
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Site $site): bool
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Site $site): bool
    {
        return $this->delete($user, $site);
    }
}
