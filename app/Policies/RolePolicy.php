<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access admin');
    }

    public function view(User $user, Role $record): bool
    {
        return $user->can('access admin');
    }

    public function create(User $user): bool
    {
        return $user->can('access admin');
    }

    public function update(User $user, Role $record): bool
    {
        return $user->can('access admin');
    }

    public function delete(User $user, Role $record): bool
    {
        return $user->can('access admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('access admin');
    }
}
