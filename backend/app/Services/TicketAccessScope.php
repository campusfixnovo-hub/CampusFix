<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TicketAccessScope
{
    public function query(User $user): Builder
    {
        $query = Ticket::query();
        if ($user->hasRole('administrator')) return $query;
        if ($user->hasRole('manager')) {
            $teamIds = $user->teams()->pluck('teams.id');
            $campusIds = $user->teams()->whereNotNull('teams.campus_id')->pluck('teams.campus_id');
            return $query->where(fn ($q) => $q->whereIn('team_id', $teamIds)->orWhereIn('campus_id', $campusIds)->orWhere('requester_id', $user->id));
        }
        if ($user->hasRole('technician')) {
            $teamIds = $user->teams()->wherePivotIn('member_role', ['technician', 'lead'])->pluck('teams.id');
            return $query->where(fn ($q) => $q->where('assigned_user_id', $user->id)->orWhereIn('team_id', $teamIds));
        }
        return $query->where('requester_id', $user->id);
    }
}
