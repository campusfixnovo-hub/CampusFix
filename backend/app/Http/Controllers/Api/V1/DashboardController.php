<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Ticket;
use App\Services\TicketAccessScope;
use App\Services\SlaClock;
use App\Services\SlaMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TicketAccessScope $scope, SlaClock $clock, SlaMetrics $slaMetrics): JsonResponse
    {
        $query = $scope->query($request->user());
        $active = ['open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'reopened'];
        $resolvedCount = (clone $query)->where('status', TicketStatus::Resolved)->count();
        $closedCount = (clone $query)->where('status', TicketStatus::Closed)->count();
        $stats = [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->whereIn('status', ['open', 'in_triage'])->count(),
            'in_progress' => (clone $query)->whereIn('status', ['awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'reopened'])->count(),
            'resolved' => $resolvedCount,
            'closed' => $closedCount,
            'completed' => $resolvedCount + $closedCount,
            'cancelled' => (clone $query)->where('status', TicketStatus::Cancelled)->count(),
            'unassigned' => (clone $query)->whereIn('status', $active)->whereNull('assigned_user_id')->count(),
            'paused' => (clone $query)->whereHas('slaPauses', fn ($pause) => $pause->whereNull('ended_at'))->count(),
            'near_due' => $slaMetrics->nearDueCount($query, $clock),
            'overdue' => (clone $query)->whereNotNull('due_at')->where('due_at', '<', now())->whereIn('status', $active)->where('status', '!=', 'awaiting_requester')->whereDoesntHave('slaPauses', fn ($pause) => $pause->whereNull('ended_at'))->count(),
        ];
        $recent = (clone $query)->with(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester', 'slaPolicy.calendar'])
            ->withCount(['slaPauses as active_sla_pause_count' => fn ($pause) => $pause->whereNull('ended_at')])->latest()->limit(8)->get();

        return response()->json(['data' => ['stats' => $stats, 'recent_tickets' => TicketResource::collection($recent)]]);
    }
}
