<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportScope
{
    public function query(User $user): Builder
    {
        $query = Ticket::query();
        if ($user->hasRole('administrator')) {
            return $query;
        }

        $teamIds = $this->teamIds($user);
        if ($user->hasRole('manager')) {
            $campusIds = DB::table('teams')->whereIn('id', $teamIds)->whereNotNull('campus_id')->pluck('campus_id');

            return $query->where(fn (Builder $tickets) => $tickets->whereIn('team_id', $teamIds)->orWhereIn('campus_id', $campusIds));
        }

        return $query->where(fn (Builder $tickets) => $tickets->where('assigned_user_id', $user->id)->orWhereIn('team_id', $teamIds));
    }

    /** @return list<int> */
    public function teamIds(User $user): array
    {
        if ($user->hasRole('administrator')) {
            return [];
        }

        $memberRoles = $user->hasRole('manager') ? ['manager'] : ['technician', 'lead'];

        return DB::table('team_members')->where('user_id', $user->id)->whereIn('member_role', $memberRoles)->pluck('team_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int>|null Null means all teams in the administrator scope. */
    public function reportTeamIds(User $user): ?array
    {
        if ($user->hasRole('administrator')) {
            return null;
        }
        if (! $user->hasRole('manager')) {
            $assignedTeamIds = DB::table('tickets')->where('assigned_user_id', $user->id)->whereNotNull('team_id')->pluck('team_id');

            return collect($this->teamIds($user))->merge($assignedTeamIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $managedTeamIds = $this->teamIds($user);
        $campusIds = DB::table('teams')->whereIn('id', $managedTeamIds)->whereNotNull('campus_id')->pluck('campus_id');

        return DB::table('teams')->whereIn('campus_id', $campusIds)->orWhereIn('id', $managedTeamIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int>|null Null means unrestricted administrator scope. */
    public function campusIds(User $user): ?array
    {
        if ($user->hasRole('administrator')) {
            return null;
        }

        $campusIds = DB::table('teams')->whereIn('id', $this->teamIds($user))->whereNotNull('campus_id')->pluck('campus_id');
        if ($user->hasRole('technician')) {
            $assignedCampusIds = $this->query($user)->distinct()->pluck('campus_id');
            $campusIds = $campusIds->merge($assignedCampusIds);
        }

        return $campusIds->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return list<int>|null Null means all active technicians. */
    public function technicianIds(User $user): ?array
    {
        if ($user->hasRole('administrator')) {
            return null;
        }
        if ($user->hasRole('technician')) {
            return [(int) $user->id];
        }

        return DB::table('team_members')->join('users', 'users.id', '=', 'team_members.user_id')
            ->whereIn('team_members.team_id', $this->reportTeamIds($user) ?? [])
            ->whereIn('team_members.member_role', ['technician', 'lead'])
            ->where('users.is_active', true)->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
