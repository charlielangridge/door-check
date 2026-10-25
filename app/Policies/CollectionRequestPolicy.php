<?php

namespace App\Policies;

use App\ChildCollectionState;
use App\Models\CollectionRequest;
use App\Models\User;

class CollectionRequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('access admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CollectionRequest $collectionRequest): bool
    {
        return $user->can('access admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('access admin');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CollectionRequest $collectionRequest): bool
    {
        return $user->can('access admin') && $collectionRequest->collection_state === ChildCollectionState::NeedsChecking
            && CollectionRequest::query()->whereKey($collectionRequest->groupId())->whereNull('adult_locked_at')->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CollectionRequest $collectionRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CollectionRequest $collectionRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CollectionRequest $collectionRequest): bool
    {
        return false;
    }
}
