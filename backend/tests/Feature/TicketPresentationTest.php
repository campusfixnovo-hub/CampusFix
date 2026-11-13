<?php

use App\Enums\TicketAuditEvent;
use App\Enums\TicketStatus;
use App\Enums\UserType;
use App\Models\BusinessCalendar;
use App\Models\BusinessHour;
use App\Models\Comment;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use App\Services\TicketSlaPresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function presentationCalendar(): BusinessCalendar
{
    $calendar = BusinessCalendar::factory()->create(['timezone' => 'America/Manaus']);
    foreach (range(1, 5) as $weekday) {
        foreach ([['opens_at' => '08:00:00', 'closes_at' => '12:00:00'], ['opens_at' => '13:00:00', 'closes_at' => '17:00:00']] as $segment => $hours) {
            BusinessHour::factory()->create([
                'business_calendar_id' => $calendar->id,
                'weekday' => $weekday,
                'segment' => $segment + 1,
                ...$hours,
            ]);
        }
    }

    return $calendar;
}

it('renders ticket history as Portuguese descriptions without exposing stored field names or values', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $team = Team::factory()->create(['name' => 'Equipe de Infraestrutura']);
    $technician = User::factory()->type(UserType::Technician)->create(['name' => 'Técnico Infraestrutura Demo']);
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id, 'team_id' => $team->id, 'assigned_user_id' => $technician->id]);

    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $requester->id, 'event_type' => TicketAuditEvent::Created, 'source' => 'web', 'new_values' => ['due_at' => '2026-11-16T17:00:00-04:00'], 'created_at' => now()->subMinutes(4)]);
    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $requester->id, 'event_type' => TicketAuditEvent::TeamAssigned, 'source' => 'web', 'new_values' => ['team_id' => $team->id], 'created_at' => now()->subMinutes(3)]);
    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $requester->id, 'event_type' => TicketAuditEvent::TechnicianAssigned, 'source' => 'web', 'new_values' => ['assigned_user_id' => $technician->id], 'created_at' => now()->subMinutes(2)]);
    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $technician->id, 'event_type' => TicketAuditEvent::StatusChanged, 'source' => 'web', 'new_values' => ['status' => 'in_progress'], 'created_at' => now()->subMinute()]);
    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $technician->id, 'event_type' => TicketAuditEvent::Resolved, 'source' => 'web', 'new_values' => ['status' => 'resolved', 'assigned_user_id' => $technician->id], 'created_at' => now()]);

    $this->actingAs($requester)->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Solicitação criada')
        ->assertSee('Equipe atribuída: Equipe de Infraestrutura.')
        ->assertSee('Técnico responsável: Técnico Infraestrutura Demo.')
        ->assertSee('Status alterado para: Em andamento.')
        ->assertSee('Solicitação resolvida pelo técnico responsável.')
        ->assertDontSee('team_id')
        ->assertDontSee('assigned_user_id')
        ->assertDontSee('in_progress');
});

it('hides internal-note events and their authors from the requester web history', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $technician = User::factory()->type(UserType::Technician)->create(['name' => 'Técnico de Nota Confidencial']);
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);
    $comment = Comment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $technician->id,
        'body' => 'Conteúdo confidencial da equipe',
        'is_internal' => true,
    ]);
    TicketHistory::create([
        'ticket_id' => $ticket->id,
        'actor_id' => $technician->id,
        'event_type' => TicketAuditEvent::CommentAdded,
        'source' => 'web',
        'new_values' => ['comment_id' => $comment->id, 'internal' => true],
        'created_at' => now(),
    ]);

    $this->actingAs($requester)->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee('Conteúdo confidencial da equipe')
        ->assertDontSee('Técnico de Nota Confidencial')
        ->assertDontSee('Nota interna registrada')
        ->assertDontSee('atualização interna');
});

it('returns translated safe history for the API and hides internal-note audit items from requesters', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $team = Team::factory()->create(['name' => 'Equipe de Infraestrutura']);
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id, 'team_id' => $team->id]);
    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $requester->id, 'event_type' => TicketAuditEvent::TeamAssigned, 'source' => 'api', 'new_values' => ['team_id' => $team->id], 'created_at' => now()]);
    TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $requester->id, 'event_type' => TicketAuditEvent::CommentAdded, 'source' => 'api', 'new_values' => ['comment_id' => 456, 'internal' => true], 'created_at' => now()->addSecond()]);

    $token = $requester->createToken('history presentation')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/tickets/'.$ticket->id.'/history')
        ->assertOk()
        ->assertJsonPath('data.0.label', 'Equipe atribuída')
        ->assertJsonPath('data.0.description', 'Equipe atribuída: Equipe de Infraestrutura.')
        ->assertJsonMissingPath('data.0.new_values')
        ->assertJsonMissingPath('data.0.metadata')
        ->assertJsonCount(1, 'data');
});

it('presents active SLA time using institutional business hours in Manaus time', function () {
    $calendar = presentationCalendar();
    $policy = SlaPolicy::factory()->create(['business_calendar_id' => $calendar->id]);
    $now = CarbonImmutable::parse('2026-11-16 10:00:00', 'America/Manaus');
    $ticket = Ticket::factory()->create([
        'sla_policy_id' => $policy->id,
        'sla_target_minutes' => 210,
        'due_at' => CarbonImmutable::parse('2026-11-16 14:30:00', 'America/Manaus'),
        'status' => TicketStatus::InProgress,
    ])->load('slaPolicy.calendar');

    $presentation = app(TicketSlaPresenter::class)->present($ticket, $now);

    expect($presentation['state'])->toBe('active')
        ->and($presentation['deadline'])->toBe('16/11/2026 14:30')
        ->and($presentation['message'])->toBe('Restam 3 h 30 min de expediente.');

    $overdue = Ticket::factory()->create([
        'sla_policy_id' => $policy->id,
        'due_at' => CarbonImmutable::parse('2026-11-16 09:00:00', 'America/Manaus'),
        'status' => TicketStatus::InProgress,
    ])->load('slaPolicy.calendar');
    expect(app(TicketSlaPresenter::class)->present($overdue, $now)['message'])->toBe('Prazo excedido há 1 h de expediente.');
});

it('presents completed, reopened, paused, cancelled, and no-policy SLA states without false deadline claims', function () {
    $calendar = presentationCalendar();
    $policy = SlaPolicy::factory()->create(['business_calendar_id' => $calendar->id]);
    $now = CarbonImmutable::parse('2026-11-16 14:00:00', 'America/Manaus');
    $presenter = app(TicketSlaPresenter::class);

    $resolvedOnTime = Ticket::factory()->create([
        'sla_policy_id' => $policy->id, 'status' => TicketStatus::Resolved,
        'due_at' => CarbonImmutable::parse('2026-11-16 15:00:00', 'America/Manaus'),
        'resolved_at' => CarbonImmutable::parse('2026-11-16 13:00:00', 'America/Manaus'),
    ])->load('slaPolicy.calendar');
    expect($presenter->present($resolvedOnTime, $now)['message'])->toBe('Concluída dentro do prazo.');

    $resolvedLate = Ticket::factory()->create([
        'sla_policy_id' => $policy->id, 'status' => TicketStatus::Closed,
        'due_at' => CarbonImmutable::parse('2026-11-16 12:00:00', 'America/Manaus'),
        'resolved_at' => CarbonImmutable::parse('2026-11-16 13:00:00', 'America/Manaus'),
        'closed_at' => CarbonImmutable::parse('2026-11-16 14:00:00', 'America/Manaus'),
    ])->load('slaPolicy.calendar');
    expect($presenter->present($resolvedLate, $now)['state'])->toBe('late')
        ->and($presenter->present($resolvedLate, $now)['message'])->toBe('Concluída fora do prazo.');

    $closedAfterOnTimeResolution = Ticket::factory()->create([
        'sla_policy_id' => $policy->id, 'status' => TicketStatus::Closed,
        'due_at' => CarbonImmutable::parse('2026-11-16 13:30:00', 'America/Manaus'),
        'resolved_at' => CarbonImmutable::parse('2026-11-16 13:00:00', 'America/Manaus'),
        'closed_at' => CarbonImmutable::parse('2026-11-16 14:00:00', 'America/Manaus'),
    ])->load('slaPolicy.calendar');
    expect($presenter->present($closedAfterOnTimeResolution, $now)['message'])->toBe('Concluída dentro do prazo.');

    $reopened = Ticket::factory()->create([
        'sla_policy_id' => $policy->id, 'status' => TicketStatus::Reopened,
        'due_at' => CarbonImmutable::parse('2026-11-16 16:00:00', 'America/Manaus'),
    ])->load('slaPolicy.calendar');
    expect($presenter->present($reopened, $now)['state'])->toBe('active')
        ->and($presenter->present($reopened, $now)['message'])->toBe('Restam 2 h de expediente.');

    $paused = Ticket::factory()->create([
        'sla_policy_id' => $policy->id, 'status' => TicketStatus::AwaitingRequester,
        'due_at' => CarbonImmutable::parse('2026-11-16 16:00:00', 'America/Manaus'),
    ])->load('slaPolicy.calendar');
    expect($presenter->present($paused, $now)['state'])->toBe('paused');

    $cancelled = Ticket::factory()->create([
        'sla_policy_id' => $policy->id, 'status' => TicketStatus::Cancelled,
        'due_at' => CarbonImmutable::parse('2026-11-16 12:00:00', 'America/Manaus'),
    ])->load('slaPolicy.calendar');
    expect($presenter->present($cancelled, $now)['state'])->toBe('cancelled');

    $withoutPolicy = Ticket::factory()->create(['sla_policy_id' => null, 'due_at' => null]);
    expect($presenter->present($withoutPolicy, $now)['state'])->toBe('none');
});
