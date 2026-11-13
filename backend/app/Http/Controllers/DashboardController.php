<?php
namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\{Ticket, User};
use App\Services\TicketAccessScope;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TicketAccessScope $scope)
    {
        $user = $request->user(); $tickets = $scope->query($user);
        $stats = ['total'=>(clone $tickets)->count(),'open'=>(clone $tickets)->whereNotIn('status',['resolved','closed','cancelled'])->count(),
            'resolved'=>(clone $tickets)->where('status','resolved')->count(),'overdue'=>(clone $tickets)->whereNotNull('due_at')->where('due_at','<',now())->whereNotIn('status',['resolved','closed','cancelled','awaiting_requester'])->count()];
        $recent = $scope->query($user)->with(['category','requester','assignedTechnician','team','slaPolicy.calendar'])->withCount(['slaPauses as active_sla_pause_count'=>fn($q)=>$q->whereNull('ended_at')])->latest()->limit(8)->get();
        $updates = $user->hasAnyRole(['student','professor','staff']) ? $user->requestedTickets()->with('category')->latest()->limit(5)->get() : collect();
        $managedTeams = $user->hasRole('manager') ? $user->teams()->where('team_members.member_role','manager')->with('campus')->withCount(['technicians'=>fn($q)=>$q->where('users.is_active',true)])->get() : collect();
        return view('dashboard.index', compact('stats','recent','updates','managedTeams'));
    }
}
