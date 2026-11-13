<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketAuditEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Rating;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Services\TicketAccessScope;
use App\Services\TicketWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request, TicketAccessScope $scope): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)], 'category_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'], 'sla' => ['nullable', Rule::in(['overdue', 'paused'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = $scope->query($request->user())->with(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester'])
            ->withCount(['slaPauses as active_sla_pause_count' => fn ($pause) => $pause->whereNull('ended_at')]);
        $this->applyFilters($query, $filters);
        if (($filters['sla'] ?? null) === 'overdue') {
            $query->where('due_at', '<', now())->whereNotNull('due_at')->whereNotIn('status', ['resolved', 'closed', 'cancelled', 'awaiting_requester'])->whereDoesntHave('slaPauses', fn ($pause) => $pause->whereNull('ended_at'));
        } elseif (($filters['sla'] ?? null) === 'paused') {
            $query->whereHas('slaPauses', fn ($pause) => $pause->whereNull('ended_at'));
        }

        return TicketResource::collection($query->latest()->paginate($filters['per_page'] ?? 15)->withQueryString())->response();
    }

    public function store(Request $request, TicketWorkflow $workflow): JsonResponse
    {
        $this->authorize('create', Ticket::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:20000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['required', Rule::enum(TicketPriority::class)], 'campus_id' => ['required', 'integer', Rule::exists('campuses', 'id')->where('is_active', true)],
            'building_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'location_details' => ['nullable', 'string', 'max:2000'],
        ]);
        $ticket = $workflow->create($request->user(), $data, 'api');
        $ticket->load(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester']);

        return (new TicketResource($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        $this->authorize('view', $ticket);

        return new TicketResource($ticket->load(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester', 'slaPauses', 'slaPolicy.calendar'])
            ->loadCount(['slaPauses as active_sla_pause_count' => fn ($pause) => $pause->whereNull('ended_at')]));
    }

    public function update(Request $request, Ticket $ticket, TicketWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $ticket);
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:180'], 'description' => ['sometimes', 'required', 'string', 'max:20000']]);
        abort_if($data === [], 422, 'Informe ao menos um campo permitido.');
        $workflow->updateRequesterDetails($ticket, $request->user(), $data);

        return response()->json(['data' => new TicketResource($ticket->load(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester']))]);
    }

    public function status(Request $request, Ticket $ticket, TicketWorkflow $workflow): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(TicketStatus::class)]]);
        $next = TicketStatus::from($data['status']);
        $this->authorize($next === TicketStatus::Cancelled ? 'cancel' : 'updateStatus', $ticket);
        $workflow->changeStatus($ticket, $request->user(), $next, 'api');

        return response()->json(['data' => new TicketResource($ticket->fresh()->load(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester']))]);
    }

    public function assignment(Request $request, Ticket $ticket, TicketWorkflow $workflow): JsonResponse
    {
        $this->authorize('assign', $ticket);
        $data = $request->validate(['team_id' => ['nullable', 'integer'], 'assigned_user_id' => ['nullable', 'integer']]);
        $workflow->assign($ticket, $request->user(), $data['team_id'] ?? null, $data['assigned_user_id'] ?? null, 'api');

        return response()->json(['data' => new TicketResource($ticket->fresh()->load(['category', 'campus', 'building', 'location', 'team', 'assignedTechnician', 'requester']))]);
    }

    public function assignmentOptions(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('assign', $ticket);
        abort_if(in_array($ticket->status, [TicketStatus::Closed, TicketStatus::Cancelled], true), 422, 'Chamados encerrados ou cancelados não podem ser atribuídos.');

        $teams = Team::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $ticket->campus_id))
            ->with(['technicians' => function ($technicians): void {
                $technicians->where('users.is_active', true)
                    ->whereHas('roles', fn ($roles) => $roles->where('name', 'technician')->where('guard_name', 'web'))
                    ->orderBy('users.name');
            }])->orderBy('name')->get();

        return response()->json(['data' => [
            'ticket_id' => $ticket->id,
            'current_team_id' => $ticket->team_id,
            'current_assigned_user_id' => $ticket->assigned_user_id,
            'teams' => $teams->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'technicians' => $team->technicians->map(fn ($technician): array => ['id' => $technician->id, 'name' => $technician->name])->values(),
            ])->values(),
        ]]);
    }

    public function history(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $items = $ticket->histories()->with('actor:id,name')->paginate(30);
        $items->getCollection()->transform(fn (TicketHistory $event): array => [
            'id' => $event->id, 'event' => $event->event_type?->value, 'actor' => $event->actor?->only(['id', 'name']),
            'old_values' => $event->old_values, 'new_values' => $event->new_values, 'metadata' => $event->metadata,
            'occurred_at' => $event->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $items->items(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function rating(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $rating = $ticket->rating;
        if ($rating && (int) $ticket->requester_id !== (int) $request->user()->id && ! $request->user()->hasAnyRole(['technician', 'manager', 'administrator'])) {
            abort(404);
        }

        return response()->json(['data' => $rating ? ['score' => $rating->score, 'comment' => $rating->comment, 'created_at' => $rating->created_at?->toISOString()] : null]);
    }

    public function rate(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        abort_unless((int) $ticket->requester_id === (int) $request->user()->id && $ticket->status === TicketStatus::Resolved, 403);
        $data = $request->validate(['score' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']]);
        abort_if($ticket->rating()->exists(), 409, 'Esta solicitação já foi avaliada.');
        $rating = DB::transaction(function () use ($ticket, $request, $data): Rating {
            $rating = $ticket->rating()->create($data + ['user_id' => $request->user()->id]);
            TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $request->user()->id, 'event_type' => TicketAuditEvent::Rated, 'source' => 'api', 'metadata' => ['score' => $data['score']], 'created_at' => now()]);

            return $rating;
        });

        return response()->json(['data' => ['score' => $rating->score, 'comment' => $rating->comment]], 201);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query->when($filters['q'] ?? null, fn ($builder, $value) => $builder->where(fn ($search) => $search->where('code', 'like', "%{$value}%")->orWhere('title', 'like', "%{$value}%")))
            ->when($filters['status'] ?? null, fn ($builder, $value) => $builder->where('status', $value))
            ->when($filters['priority'] ?? null, fn ($builder, $value) => $builder->where('priority', $value))
            ->when($filters['category_id'] ?? null, fn ($builder, $value) => $builder->where('category_id', $value))
            ->when($filters['team_id'] ?? null, fn ($builder, $value) => $builder->where('team_id', $value));
    }
}
