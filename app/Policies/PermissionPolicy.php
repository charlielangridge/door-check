<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access admin');
    }

    public function view(User $user, Permission $record): bool
    {
        return $user->can('access admin');
    }

    public function create(User $user): bool
    {
        return $user->can('access admin');
    }

    public function update(User $user, Permission $record): bool
    {
        return $user->can('access admin');
    }

    public function delete(User $user, Permission $record): bool
    {
        return $user->can('access admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('access admin');
    }
}
