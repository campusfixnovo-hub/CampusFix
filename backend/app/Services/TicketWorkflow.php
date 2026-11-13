<?php

namespace App\Services;

use App\Enums\TicketAuditEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Building;
use App\Models\BusinessCalendar;
use App\Models\Campus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Location;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TicketWorkflow
{
    public function __construct(private SlaClock $clock) {}

    public function create(User $actor, array $data, string $source = 'web'): Ticket
    {
        Gate::forUser($actor)->authorize('create', Ticket::class);
        foreach ([
            'category_id' => [Category::class, 'Categoria indisponível.'],
            'campus_id' => [Campus::class, 'Campus indisponível.'],
        ] as $field => [$model, $message]) {
            if (! $model::query()->whereKey($data[$field] ?? null)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
        $buildingId = $data['building_id'] ?? null;
        $locationId = $data['location_id'] ?? null;
        if ($buildingId && ! Building::query()->whereKey($buildingId)->where('campus_id', $data['campus_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['building_id' => 'O prédio deve estar ativo e pertencer ao campus informado.']);
        }
        if ($locationId && (! $buildingId || ! Location::query()->whereKey($locationId)->where('building_id', $buildingId)->where('is_active', true)->exists())) {
            throw ValidationException::withMessages(['location_id' => 'O local deve estar ativo e pertencer ao prédio informado.']);
        }

        return DB::transaction(function () use ($actor, $data, $source) {
            $priority = TicketPriority::from($data['priority']);
            $policy = SlaPolicy::query()->where('priority', $priority->value)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $data['category_id']))
                ->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))
                ->orderByRaw('category_id IS NULL')->first();
            $ticketData = array_merge(Arr::only($data, ['title', 'description', 'category_id', 'priority', 'campus_id', 'building_id', 'location_id', 'location_details']), ['requester_id' => $actor->id, 'status' => TicketStatus::Open->value,
                'sla_policy_id' => $policy?->id, 'sla_target_minutes' => $policy?->target_minutes,
                'due_at' => $policy ? $this->clock->addBusinessMinutes(CarbonImmutable::now(), $policy->target_minutes, $policy->calendar) : null]);
            $ticket = Ticket::create($ticketData);
            $ticket->forceFill(['code' => 'CF-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->audit($ticket, $actor, TicketAuditEvent::Created, [], [
                'title' => $ticket->title, 'priority' => $priority->value, 'category_id' => $ticket->category_id,
                'campus_id' => $ticket->campus_id, 'building_id' => $ticket->building_id,
                'location_id' => $ticket->location_id, 'sla_policy_id' => $ticket->sla_policy_id,
                'sla_target_minutes' => $ticket->sla_target_minutes, 'due_at' => $ticket->due_at?->toISOString(),
            ], $source);

            return $ticket;
        });
    }

    public function changeStatus(Ticket $ticket, User $actor, TicketStatus $next, string $source = 'web'): void
    {
        Gate::forUser($actor)->authorize($next === TicketStatus::Cancelled ? 'cancel' : 'updateStatus', $ticket);
        DB::transaction(function () use ($ticket, $actor, $next, $source) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $old = $ticket->status;
            Gate::forUser($actor)->authorize($next === TicketStatus::Cancelled ? 'cancel' : 'updateStatus', $ticket);
            if ($old === $next) {
                return;
            }
            $oldFirstResponse = $ticket->first_response_at;
            $oldResolvedAt = $ticket->resolved_at;
            $oldClosedAt = $ticket->closed_at;
            $allowed = [
                'open' => ['in_triage', 'awaiting_service', 'cancelled'], 'in_triage' => ['awaiting_service', 'in_progress', 'awaiting_requester', 'cancelled'],
                'awaiting_service' => ['in_progress', 'awaiting_material', 'awaiting_requester', 'cancelled'], 'in_progress' => ['awaiting_material', 'awaiting_requester', 'resolved', 'cancelled'],
                'awaiting_material' => ['in_progress', 'awaiting_requester', 'resolved', 'cancelled'], 'awaiting_requester' => ['in_progress', 'awaiting_service', 'cancelled'],
                'resolved' => ['reopened', 'closed'], 'reopened' => ['in_progress', 'awaiting_material', 'awaiting_requester', 'resolved'], 'closed' => ['reopened'],
            ];
            if (! in_array($next->value, $allowed[$old->value] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Transição de status inválida.']);
            }
            if ($old === TicketStatus::AwaitingRequester) {
                $this->endPause($ticket, $actor, $source);
            }
            if ($next === TicketStatus::AwaitingRequester) {
                $this->startPause($ticket, $actor, $source);
            }
            $ticket->status = $next;
            if ($next === TicketStatus::Resolved) {
                $ticket->resolved_at = now();
            }
            if ($next === TicketStatus::Closed) {
                $ticket->closed_at = now();
            }
            if ($next === TicketStatus::Reopened) {
                $ticket->resolved_at = null;
                $ticket->closed_at = null;
            }
            if (in_array($next, [TicketStatus::InProgress, TicketStatus::AwaitingMaterial], true) && ! $ticket->first_response_at) {
                $ticket->first_response_at = now();
            }
            $ticket->save();
            $event = $next === TicketStatus::Resolved ? TicketAuditEvent::Resolved : ($next === TicketStatus::Closed ? TicketAuditEvent::Closed : ($next === TicketStatus::Reopened ? TicketAuditEvent::Reopened : TicketAuditEvent::StatusChanged));
            if ($next === TicketStatus::Cancelled) {
                $event = TicketAuditEvent::Cancelled;
            }
            $this->audit($ticket, $actor, $event,
                ['status' => $old->value, 'first_response_at' => $oldFirstResponse?->toISOString(), 'resolved_at' => $oldResolvedAt?->toISOString(), 'closed_at' => $oldClosedAt?->toISOString(), 'team_id' => $ticket->team_id, 'assigned_user_id' => $ticket->assigned_user_id],
                ['status' => $next->value, 'first_response_at' => $ticket->first_response_at?->toISOString(), 'resolved_at' => $ticket->resolved_at?->toISOString(), 'closed_at' => $ticket->closed_at?->toISOString(), 'team_id' => $ticket->team_id, 'assigned_user_id' => $ticket->assigned_user_id], $source);
        });
    }

    public function assign(Ticket $ticket, User $actor, ?int $teamId, ?int $technicianId, string $source = 'web'): void
    {
        Gate::forUser($actor)->authorize('assign', $ticket);
        $team = $teamId ? Team::query()->whereKey($teamId)->where('is_active', true)->first() : null;
        if ($teamId && ! $team) {
            throw ValidationException::withMessages(['team_id' => 'A equipe selecionada não está ativa.']);
        }
        if ($team && $team->campus_id !== null && (int) $team->campus_id !== (int) $ticket->campus_id) {
            throw ValidationException::withMessages(['team_id' => 'A equipe não atende o campus deste chamado.']);
        }
        if ($technicianId && ! $teamId) {
            throw ValidationException::withMessages(['team_id' => 'Selecione uma equipe para atribuir um técnico.']);
        }
        if ($technicianId) {
            $technician = User::query()->whereKey($technicianId)->where('is_active', true)->first();
            if (! $technician || ! $technician->hasRole('technician')) {
                throw ValidationException::withMessages(['assigned_user_id' => 'O técnico precisa ter uma conta ativa e a função de técnico.']);
            }
            if (! DB::table('team_members')->where('team_id', $teamId)->where('user_id', $technicianId)->whereIn('member_role', ['technician', 'lead'])->exists()) {
                throw ValidationException::withMessages(['assigned_user_id' => 'O técnico não pertence à equipe selecionada.']);
            }
        }
        DB::transaction(function () use ($ticket, $actor, $teamId, $technicianId, $source) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('assign', $ticket);
            if (in_array($ticket->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['assignment' => 'Chamados encerrados ou cancelados não podem ser atribuídos.']);
            }
            $currentTeam = $teamId ? Team::query()->whereKey($teamId)->where('is_active', true)->first() : null;
            if ($teamId && ! $currentTeam) {
                throw ValidationException::withMessages(['team_id' => 'A equipe selecionada não está ativa.']);
            }
            if ($currentTeam && $currentTeam->campus_id !== null && (int) $currentTeam->campus_id !== (int) $ticket->campus_id) {
                throw ValidationException::withMessages(['team_id' => 'A equipe não atende o campus deste chamado.']);
            }
            $lockedTechnician = $technicianId ? User::query()->whereKey($technicianId)->where('is_active', true)->first() : null;
            if ($technicianId && (! $lockedTechnician || ! $lockedTechnician->hasRole('technician')
                || ! DB::table('team_members')->where('team_id', $teamId)->where('user_id', $technicianId)->whereIn('member_role', ['technician', 'lead'])->exists())) {
                throw ValidationException::withMessages(['assigned_user_id' => 'O técnico precisa estar ativo e vinculado à equipe selecionada.']);
            }
            $oldTeam = $ticket->team_id;
            $oldUser = $ticket->assigned_user_id;
            $ticket->forceFill(['team_id' => $teamId, 'assigned_user_id' => $technicianId])->save();
            if ($oldTeam !== $teamId) {
                $this->audit($ticket, $actor, TicketAuditEvent::TeamAssigned, ['team_id' => $oldTeam], ['team_id' => $teamId], $source);
            }
            if ($oldUser !== $technicianId) {
                $this->audit($ticket, $actor, TicketAuditEvent::TechnicianAssigned, ['assigned_user_id' => $oldUser], ['assigned_user_id' => $technicianId], $source);
            }
        });
    }

    /** @param array{title?: string, description?: string} $data */
    public function updateRequesterDetails(Ticket $ticket, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('view', $ticket);
        if ((int) $ticket->requester_id !== (int) $actor->id || ! in_array($ticket->status, [TicketStatus::Open, TicketStatus::InTriage], true)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Somente o solicitante pode editar os dados antes do início do atendimento.');
        }

        DB::transaction(function () use ($ticket, $actor, $data): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('view', $ticket);
            if ((int) $ticket->requester_id !== (int) $actor->id || ! in_array($ticket->status, [TicketStatus::Open, TicketStatus::InTriage], true)) {
                throw new \Illuminate\Auth\Access\AuthorizationException('O chamado não pode mais ser editado pelo solicitante.');
            }
            $old = $ticket->only(array_keys($data));
            if ($old === $data) {
                return;
            }
            $ticket->fill($data)->save();
            $this->audit($ticket, $actor, TicketAuditEvent::DetailsUpdated, $old, $data, 'api');
        });
    }

    public function changePriority(Ticket $ticket, User $actor, TicketPriority $priority): void
    {
        Gate::forUser($actor)->authorize('assign', $ticket);
        DB::transaction(function () use ($ticket, $actor, $priority) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('assign', $ticket);
            $old = $ticket->priority;
            if ($old === $priority) {
                return;
            }
            if ($ticket->slaPauses()->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['priority' => 'Retome o chamado antes de alterar a prioridade para preservar a contagem SLA.']);
            }
            $ticket->priority = $priority;
            $policy = SlaPolicy::query()->where('priority', $priority->value)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $ticket->category_id))
                ->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))
                ->orderByRaw('category_id IS NULL')->first();
            if (! $policy) {
                throw ValidationException::withMessages(['priority' => 'Não há uma política SLA ativa para esta prioridade e categoria.']);
            }
            if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed, TicketStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['priority' => 'A prioridade de um chamado finalizado não pode ser alterada.']);
            }
            $oldDue = $ticket->due_at;
            $oldPolicyId = $ticket->sla_policy_id;
            $oldTarget = $ticket->sla_target_minutes;
            $elapsed = $ticket->slaPolicy?->calendar
                ? $this->clock->businessMinutesBetween(CarbonImmutable::parse($ticket->created_at), CarbonImmutable::now(), $ticket->slaPolicy->calendar)
                    - (int) $ticket->slaPauses()->whereNotNull('ended_at')->sum('duration_minutes')
                : 0;
            $remaining = max(0, $policy->target_minutes - max(0, $elapsed));
            $ticket->sla_policy_id = $policy->id;
            $ticket->sla_target_minutes = $policy->target_minutes;
            $ticket->due_at = $this->clock->addBusinessMinutes(CarbonImmutable::now(), $remaining, $policy->calendar);
            $ticket->save();
            $this->audit($ticket, $actor, TicketAuditEvent::PriorityChanged,
                ['priority' => $old->value, 'sla_policy_id' => $oldPolicyId, 'sla_target_minutes' => $oldTarget, 'due_at' => $oldDue?->toISOString()],
                ['priority' => $priority->value, 'sla_policy_id' => $policy->id, 'sla_target_minutes' => $policy->target_minutes, 'due_at' => $ticket->due_at?->toISOString()]);
        });
    }

    public function changeLocation(Ticket $ticket, User $actor, int $campusId, ?int $buildingId, ?int $locationId, ?string $details): void
    {
        Gate::forUser($actor)->authorize('updateLocation', $ticket);
        $campus = Campus::query()->whereKey($campusId)->where('is_active', true)->first();
        if (! $campus) {
            throw ValidationException::withMessages(['campus_id' => 'Selecione um campus ativo.']);
        }
        $building = $buildingId ? Building::query()->whereKey($buildingId)->where('is_active', true)->first() : null;
        if ($buildingId && (! $building || (int) $building->campus_id !== $campusId)) {
            throw ValidationException::withMessages(['building_id' => 'O prédio precisa estar ativo e pertencer ao campus selecionado.']);
        }
        $location = $locationId ? Location::query()->whereKey($locationId)->where('is_active', true)->first() : null;
        if ($locationId && (! $location || ! $building || (int) $location->building_id !== $buildingId)) {
            throw ValidationException::withMessages(['location_id' => 'O local precisa estar ativo e pertencer ao prédio selecionado.']);
        }

        DB::transaction(function () use ($ticket, $actor, $campusId, $buildingId, $locationId, $details) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('updateLocation', $ticket);
            $old = ['campus_id' => $ticket->campus_id, 'building_id' => $ticket->building_id, 'location_id' => $ticket->location_id, 'location_details' => $ticket->location_details];
            $new = ['campus_id' => $campusId, 'building_id' => $buildingId, 'location_id' => $locationId, 'location_details' => $details];
            if ($old === $new) {
                return;
            }
            $ticket->forceFill($new)->save();
            $this->audit($ticket, $actor, TicketAuditEvent::LocationChanged, $old, $new);
        });
    }

    public function comment(Ticket $ticket, User $actor, string $body, bool $internal, string $source = 'web'): Comment
    {
        Gate::forUser($actor)->authorize('view', $ticket);
        if ($internal) {
            Gate::forUser($actor)->authorize('viewInternal', $ticket);
        }
        $comment = DB::transaction(function () use ($ticket, $actor, $body, $internal, $source) {
            $comment = $ticket->comments()->create(['user_id' => $actor->id, 'body' => $body, 'is_internal' => $internal]);
            $this->audit($ticket, $actor, TicketAuditEvent::CommentAdded, [], ['comment_id' => $comment->id, 'internal' => $internal], $source);

            return $comment;
        });

        return $comment;
    }

    private function startPause(Ticket $ticket, User $actor, string $source): void
    {
        if ($ticket->slaPauses()->whereNull('ended_at')->exists()) {
            return;
        }
        $ticket->slaPauses()->create(['started_by' => $actor->id, 'start_reason' => 'awaiting_requester', 'started_at' => now()]);
        $this->audit($ticket, $actor, TicketAuditEvent::SlaPaused, [], ['status' => 'awaiting_requester', 'reason' => 'awaiting_requester', 'started_at' => now()->toISOString()], $source);
    }

    private function endPause(Ticket $ticket, User $actor, string $source): void
    {
        $pause = $ticket->slaPauses()->whereNull('ended_at')->latest('started_at')->first();
        if (! $pause) {
            return;
        }
        $ended = CarbonImmutable::now();
        $started = CarbonImmutable::parse($pause->started_at);
        $calendar = $ticket->slaPolicy?->calendar ?? BusinessCalendar::where('is_default', true)->first();
        $duration = $calendar ? $this->clock->businessMinutesBetween($started, $ended, $calendar) : $started->diffInMinutes($ended);
        $pause->forceFill(['ended_by' => $actor->id, 'end_reason' => 'status_changed', 'ended_at' => $ended, 'duration_minutes' => $duration])->save();
        $oldDue = $ticket->due_at;
        if ($ticket->due_at && $calendar) {
            $ticket->forceFill(['due_at' => $this->clock->addBusinessMinutes(CarbonImmutable::parse($ticket->due_at), $duration, $calendar)])->save();
        }
        $this->audit($ticket, $actor, TicketAuditEvent::SlaResumed, ['started_at' => $started->toISOString(), 'due_at' => $oldDue?->toISOString()], ['ended_at' => $ended->toISOString(), 'duration_minutes' => $duration, 'due_at' => $ticket->due_at?->toISOString()], $source);
    }

    private function audit(Ticket $ticket, User $actor, TicketAuditEvent $event, array $old, array $new, string $source = 'web'): void
    {
        TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $actor->id, 'event_type' => $event, 'source' => $source, 'old_values' => $old ?: null, 'new_values' => $new ?: null, 'created_at' => now()]);
    }
}
