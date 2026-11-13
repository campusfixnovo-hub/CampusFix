<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole([
            'student', 'professor', 'staff', 'technician', 'manager', 'administrator',
        ]);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active || $ticket->trashed()) {
            return false;
        }

        if ($user->hasRole('administrator') || (int) $ticket->requester_id === (int) $user->id) {
            return true;
        }

        if ($user->hasRole('technician')) {
            if ((int) $ticket->assigned_user_id === (int) $user->id) {
                return true;
            }

            return $ticket->team_id !== null && $user->teams()
                ->whereKey($ticket->team_id)
                ->wherePivotIn('member_role', ['technician', 'lead'])
                ->exists();
        }

        if ($user->hasRole('manager')) {
            $teams = $user->teams();

            if ($ticket->team_id !== null && (clone $teams)->whereKey($ticket->team_id)->exists()) {
                return true;
            }

            $campusIds = (clone $teams)->whereNotNull('teams.campus_id')->pluck('teams.campus_id');

            return $campusIds->contains((int) $ticket->campus_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole([
            'student', 'professor', 'staff', 'technician', 'manager', 'administrator',
        ]);
    }

    public function updateStatus(User $user, Ticket $ticket): bool
    {
        if (! $this->view($user, $ticket)) {
            return false;
        }

        return $user->hasAnyRole(['technician', 'manager', 'administrator']);
    }

    public function cancel(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active) return false;
        if ($user->hasRole('administrator')) return true;
        if ((int) $ticket->requester_id === (int) $user->id
            && in_array($ticket->status->value, ['open', 'in_triage'], true)) return true;
        return $user->hasAnyRole(['technician', 'manager']) && $this->view($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active) return false;
        if ($user->hasRole('administrator')) return true;
        if (! $user->hasRole('manager')) return false;

        $teams = $user->teams();
        if ($ticket->team_id !== null && (clone $teams)->whereKey($ticket->team_id)->exists()) return true;
        return (clone $teams)->where('teams.campus_id', $ticket->campus_id)->exists();
    }

    public function updateLocation(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active) return false;
        if ($user->hasRole('administrator')) return true;
        if ((int) $ticket->requester_id === (int) $user->id
            && in_array($ticket->status->value, ['open', 'in_triage'], true)) return true;
        if ($user->hasRole('manager')) return $this->assign($user, $ticket);
        return $user->hasRole('technician') && $this->view($user, $ticket)
            && ! in_array($ticket->status->value, ['closed', 'cancelled'], true);
    }

    public function viewInternal(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)
            && $user->hasAnyRole(['technician', 'manager', 'administrator']);
    }
}
