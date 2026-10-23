<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChildPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Child $child): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Child $child): bool
    {
        return true;
    }

    public function delete(User $user, Child $child): bool
    {
        return true;
    }

    public function restore(User $user, Child $child): bool
    {
        return true;
    }

    public function forceDelete(User $user, Child $child): bool
    {
        return true;
    }
}
