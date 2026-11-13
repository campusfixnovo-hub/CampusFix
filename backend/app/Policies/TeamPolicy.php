<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['administrator', 'manager']);
    }

    public function view(User $user, Team $team): bool
    {
        if (! $user->is_active) return false;
        if ($user->hasRole('administrator')) return true;

        return $user->hasRole('manager') && $user->can('teams.manage')
            && $user->teams()->whereKey($team->id)->where('team_members.member_role', 'manager')->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole('administrator') && $user->can('teams.manage');
    }

    public function update(User $user, Team $team): bool
    {
        return $this->view($user, $team) && $user->can('teams.manage');
    }

    public function deactivate(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }
}
