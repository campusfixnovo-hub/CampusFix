<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function create(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasRole('administrator')
            && $actor->can('users.manage')
            && $actor->can('roles.manage');
    }

    public function toggleActive(User $actor, User $target): bool
    {
        return $actor->is_active && (int) $actor->id !== (int) $target->id
            && $actor->hasRole('administrator') && $actor->can('users.manage');
    }

    public function assignRole(User $actor, User $target): bool
    {
        return $actor->is_active
            && (int) $actor->id !== (int) $target->id
            && $actor->hasRole('administrator')
            && $actor->can('roles.manage');
    }
}
