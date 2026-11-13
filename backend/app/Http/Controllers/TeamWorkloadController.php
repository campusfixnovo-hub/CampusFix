<?php

namespace App\Http\Controllers;

use App\Enums\{TicketPriority, TicketStatus};
use App\Models\{Team, Ticket, User};
use App\Services\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TeamWorkloadController extends Controller
{
    public function __invoke(Request $request, SlaClock $clock)
    {
        $user = $request->user();
        abort_unless($user->is_active && $user->hasAnyRole(['administrator', 'manager']), 403);

        $teamQuery = Team::query()->with(['campus', 'technicians', 'leader'])->withCount('members', 'tickets');
        if ($user->hasRole('manager')) {
            $teamQuery->whereHas('members', fn ($q) => $q->whereKey($user->id)->where('team_members.member_role', 'manager'));
        }
        $teams = $teamQuery->orderBy('name')->get();
        $teamIds = $teams->modelKeys();
        $campusIds = $teams->pluck('campus_id')->filter()->unique()->values()->all();

        $query = Ticket::query()->with(['team', 'assignedTechnician', 'category', 'slaPolicy.calendar'])
            ->withCount(['slaPauses as active_sla_pause_count' => fn ($q) => $q->whereNull('ended_at')]);
        if (! $user->hasRole('administrator')) {
            $query->where(fn ($q) => $q->whereIn('team_id', $teamIds)->orWhereIn('campus_id', $campusIds));
        }

        $selectedTeam = null;
        if ($request->filled('team_id')) {
            $selectedTeam = $teams->firstWhere('id', (int) $request->input('team_id'));
            abort_unless($selectedTeam, 403);
            $query->where('team_id', $selectedTeam->id);
        }
        if ($request->filled('technician_id')) {
            $technician = User::role('technician')->where('is_active', true)->findOrFail($request->integer('technician_id'));
            $eligibleTeams = $selectedTeam ? collect([$selectedTeam]) : $teams;
            $allowedTechnicianIds = $eligibleTeams->flatMap(fn (Team $team) => $team->technicians)->pluck('id')->map(fn ($id) => (int) $id)->unique()->all();
            abort_unless(in_array($technician->id, $allowedTechnicianIds, true), 403);
            $query->where('assigned_user_id', $technician->id);
        }
        if ($request->filled('priority')) {
            $request->validate(['priority' => ['required', \Illuminate\Validation\Rule::enum(TicketPriority::class)]]);
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('status')) {
            $request->validate(['status' => ['required', \Illuminate\Validation\Rule::enum(TicketStatus::class)]]);
            $query->where('status', $request->input('status'));
        }

        $activeStatuses = ['open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'reopened'];
        $total = (clone $query)->count();
        $openCount = (clone $query)->whereIn('status', ['open', 'in_triage', 'awaiting_service'])->count();
        $inProgressCount = (clone $query)->whereIn('status', ['in_progress', 'awaiting_material', 'awaiting_requester'])->count();
        $overdueCount = (clone $query)->whereNotNull('due_at')->where('due_at', '<', now())->whereIn('status', $activeStatuses)->where('status', '!=', 'awaiting_requester')->whereDoesntHave('slaPauses', fn ($q) => $q->whereNull('ended_at'))->count();
        $unassignedCount = (clone $query)->whereIn('status', $activeStatuses)->whereNull('assigned_user_id')->count();

        $nearDueCandidates = (clone $query)->whereIn('status', $activeStatuses)
            ->whereNotNull('due_at')->whereBetween('due_at', [now(), now()->addDays(14)])->get();
        $nearDue = $nearDueCandidates->filter(function (Ticket $ticket) use ($clock): bool {
            if ($ticket->active_sla_pause_count > 0 || ! $ticket->due_at) return false;
            $calendar = $ticket->slaPolicy?->calendar;
            $minutes = $calendar
                ? $clock->businessMinutesBetween(CarbonImmutable::now(), CarbonImmutable::parse($ticket->due_at), $calendar)
                : now()->diffInMinutes($ticket->due_at);
            return $minutes >= 0 && $minutes <= ($ticket->slaPolicy?->warn_before_minutes ?? 240);
        });

        $visibleTeams = $selectedTeam ? collect([$selectedTeam]) : $teams;
        $technicianIds = $visibleTeams->flatMap(fn (Team $team) => $team->technicians->pluck('id'))->unique()->values();
        $technicians = User::query()->with('teams')->where('is_active', true)->whereIn('id', $technicianIds)->orderBy('name')->get();
        foreach ($technicians as $technician) {
            $assigned = clone $query;
            $technician->setAttribute('workload_open', (clone $assigned)->where('assigned_user_id', $technician->id)->whereIn('status', ['open','in_triage','awaiting_service'])->count());
            $technician->setAttribute('workload_in_progress', (clone $assigned)->where('assigned_user_id', $technician->id)->whereIn('status', ['in_progress','awaiting_material','awaiting_requester'])->count());
            $technician->setAttribute('workload_overdue', (clone $assigned)->where('assigned_user_id', $technician->id)->whereNotNull('due_at')->where('due_at','<',now())->whereIn('status',$activeStatuses)->where('status','!=','awaiting_requester')->whereDoesntHave('slaPauses',fn($q)=>$q->whereNull('ended_at'))->count());
            $technician->setAttribute('workload_near_due', $nearDue->where('assigned_user_id', $technician->id)->count());
            $technician->setAttribute('workload_total', $technician->workload_open + $technician->workload_in_progress);
        }

        foreach ($visibleTeams as $team) {
            $teamTickets = clone $query;
            $teamTickets->where('team_id', $team->id);
            $team->setAttribute('workload_open', (clone $teamTickets)->whereIn('status',['open','in_triage','awaiting_service'])->count());
            $team->setAttribute('workload_in_progress', (clone $teamTickets)->whereIn('status',['in_progress','awaiting_material','awaiting_requester'])->count());
            $team->setAttribute('workload_overdue', (clone $teamTickets)->whereNotNull('due_at')->where('due_at','<',now())->whereIn('status',$activeStatuses)->where('status','!=','awaiting_requester')->whereDoesntHave('slaPauses',fn($q)=>$q->whereNull('ended_at'))->count());
        }

        $tickets = $query->latest()->paginate(15)->withQueryString();
        $teams = $visibleTeams->values();
        return view('teams.workload', compact('teams','technicians','tickets','total','openCount','inProgressCount','overdueCount','unassignedCount','nearDue','selectedTeam'));
    }
}
