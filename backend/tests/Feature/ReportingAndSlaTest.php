<?php

use App\Enums\TicketAuditEvent;
use App\Enums\TicketStatus;
use App\Enums\UserType;
use App\Models\BusinessCalendar;
use App\Models\Campus;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketSlaPause;
use App\Models\User;
use App\Services\ReportScope;
use App\Services\SlaClock;
use App\Services\TicketWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

function reportingAdministrator(): User
{
    $user = User::factory()->type(UserType::Administrator)->create();
    $user->assignRole('administrator');

    return $user;
}

it('calculates report totals using date and priority filters and omits unresolved tickets from resolution averages', function () {
    $admin = reportingAdministrator();
    $campus = Campus::factory()->create();
    $category = Category::factory()->create();
    $resolved = Ticket::factory()->create([
        'campus_id' => $campus->id, 'category_id' => $category->id, 'priority' => 'critical',
        'created_at' => now()->subHours(5), 'first_response_at' => now()->subHours(4), 'resolved_at' => now()->subHour(),
        'status' => TicketStatus::Resolved->value, 'due_at' => now()->addHour(),
    ]);
    $unresolved = Ticket::factory()->create([
        'campus_id' => $campus->id, 'category_id' => $category->id, 'priority' => 'critical',
        'created_at' => now()->subHours(3), 'resolved_at' => null, 'first_response_at' => null,
        'status' => TicketStatus::InProgress->value, 'due_at' => now()->addDay(),
    ]);
    Ticket::factory()->create([
        'campus_id' => $campus->id, 'category_id' => $category->id, 'priority' => 'low',
        'created_at' => now()->subDays(45),
    ]);

    $response = $this->actingAs($admin)->get(route('reports.index', [
        'date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString(),
        'campus_id' => $campus->id, 'category_id' => $category->id, 'priority' => 'critical',
    ]))->assertOk()->assertViewHas('total', 2);

    expect($response->viewData('durationMetrics')['resolution_count'])->toBe(1)
        ->and($response->viewData('durationMetrics')['first_response_count'])->toBe(1)
        ->and($response->viewData('resolvedCount'))->toBe(1)
        ->and($response->viewData('withinSlaPercent'))->toBe(100.0)
        ->and($response->viewData('overSlaPercent'))->toBe(0.0)
        ->and($unresolved->resolved_at)->toBeNull()
        ->and($resolved->resolved_at)->not->toBeNull();
});

it('uses singular and plural forms consistently in report duration and category summaries', function () {
    $admin = reportingAdministrator();
    $campus = Campus::factory()->create();
    $category = Category::factory()->create();
    $date = now()->toDateString();
    $makeResolvedTicket = fn () => Ticket::factory()->create([
        'campus_id' => $campus->id,
        'category_id' => $category->id,
        'status' => TicketStatus::Resolved->value,
        'created_at' => now()->subHours(2),
        'first_response_at' => now()->subHour(),
        'resolved_at' => now(),
        'due_at' => null,
    ]);

    $makeResolvedTicket();
    $filters = ['date_from' => $date, 'date_to' => $date, 'campus_id' => $campus->id, 'category_id' => $category->id];
    $this->actingAs($admin)->get(route('reports.index', $filters))->assertOk()
        ->assertSee('1 solicitação resolvida')
        ->assertSee('1 solicitação com primeira resposta registrada')
        ->assertSee('1 chamado · 0 chamados atrasados em 0 casos mensuráveis de SLA · 1 chamado resolvido');

    $makeResolvedTicket();
    $this->actingAs($admin)->get(route('reports.index', $filters))->assertOk()
        ->assertSee('2 solicitações resolvidas')
        ->assertSee('2 solicitações com primeira resposta registrada')
        ->assertSee('2 chamados · 0 chamados atrasados em 0 casos mensuráveis de SLA · 2 chamados resolvidos');
});

it('limits manager reports and exports to the managed campus scope', function () {
    $manager = User::factory()->type(UserType::Manager)->create();
    $manager->assignRole('manager');
    $campus = Campus::factory()->create();
    $outsideCampus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $team->members()->attach($manager->id, ['member_role' => 'manager', 'joined_at' => now()]);
    $visible = Ticket::factory()->create(['campus_id' => $campus->id]);
    $hidden = Ticket::factory()->create(['campus_id' => $outsideCampus->id]);

    $report = $this->actingAs($manager)->get(route('reports.index'))->assertOk();
    expect($report->viewData('total'))->toBe(1);
    $export = $this->actingAs($manager)->get(route('reports.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($export->streamedContent())->toContain($visible->code)->not->toContain($hidden->code);
    $this->actingAs($manager)->get(route('reports.index', ['campus_id' => $outsideCampus->id]))->assertForbidden();
});

it('blocks requesters from administrative reports and CSV export', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');

    $this->actingAs($requester)->get(route('reports.index'))->assertForbidden();
    $this->actingAs($requester)->get(route('reports.export'))->assertForbidden();
});

it('restricts technician reports to their teams and their individually assigned tickets', function () {
    $technician = User::factory()->type(UserType::Technician)->create();
    $technician->assignRole('technician');
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $team->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id]);
    $assignedOutsideTeam = Ticket::factory()->create(['campus_id' => $otherCampus->id, 'team_id' => null, 'assigned_user_id' => $technician->id]);
    Ticket::factory()->create(['campus_id' => $otherCampus->id]);

    expect(app(ReportScope::class)->query($technician)->count())->toBe(2)
        ->and($assignedOutsideTeam->assigned_user_id)->toBe($technician->id);
    $this->actingAs($technician)->get(route('reports.index'))->assertForbidden();
});

it('registers SLA deadline breaches and near due notifications once while leaving paused tickets untouched', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $late = Ticket::factory()->create([
        'requester_id' => $requester->id, 'status' => TicketStatus::InProgress->value,
        'due_at' => now()->subHour(), 'sla_breached_at' => null,
    ]);
    $near = Ticket::factory()->create([
        'requester_id' => $requester->id, 'status' => TicketStatus::InProgress->value,
        'due_at' => now()->addMinutes(30), 'sla_breached_at' => null,
    ]);
    $paused = Ticket::factory()->create([
        'requester_id' => $requester->id, 'status' => TicketStatus::AwaitingRequester->value,
        'due_at' => now()->subHours(2), 'sla_breached_at' => null,
    ]);
    TicketSlaPause::factory()->create(['ticket_id' => $paused->id, 'started_by' => $requester->id]);

    $this->artisan('campusfix:sla-monitor')->assertSuccessful();
    $this->artisan('campusfix:sla-monitor')->assertSuccessful();

    expect($late->fresh()->sla_breached_at?->toDateTimeString())->toBe($late->due_at->toDateTimeString())
        ->and($paused->fresh()->sla_breached_at)->toBeNull()
        ->and($requester->notifications()->where('data->ticket_id', $late->id)->count())->toBe(1)
        ->and($requester->notifications()->where('data->ticket_id', $near->id)->count())->toBe(1)
        ->and($late->histories()->where('event_type', TicketAuditEvent::SlaBreached->value)->count())->toBe(1)
        ->and($requester->notifications()->where('data->ticket_id', $paused->id)->exists())->toBeFalse();

    $notification = $requester->notifications()->where('data->ticket_id', $late->id)->firstOrFail();
    $this->actingAs($requester)->get(route('notifications.index'))->assertOk()->assertSee($late->code)->assertSee('ultrapassado');
    $this->actingAs($requester)->patch(route('notifications.read', $notification->id))->assertRedirect();
    expect($notification->fresh()->read_at)->not->toBeNull();

    $otherUser = User::factory()->type(UserType::Student)->create();
    $otherUser->assignRole('student');
    $this->actingAs($otherUser)->patch(route('notifications.read', $notification->id))->assertNotFound();
});

it('schedules the SLA monitor every five minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'campusfix:sla-monitor'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
});

it('counts working minutes across split shifts and skips a non-working holiday', function () {
    $calendar = BusinessCalendar::factory()->create(['timezone' => 'America/Manaus']);
    $calendar->hours()->createMany([
        ['weekday' => 1, 'segment' => 1, 'opens_at' => '09:00:00', 'closes_at' => '12:00:00'],
        ['weekday' => 1, 'segment' => 2, 'opens_at' => '13:00:00', 'closes_at' => '17:00:00'],
        ['weekday' => 3, 'segment' => 1, 'opens_at' => '09:00:00', 'closes_at' => '12:00:00'],
    ]);
    $calendar->holidays()->create(['holiday_date' => '2026-11-10', 'name' => 'Feriado local', 'is_working_day' => false]);

    $minutes = app(SlaClock::class)->businessMinutesBetween(
        CarbonImmutable::parse('2026-11-09 11:00:00', 'America/Manaus'),
        CarbonImmutable::parse('2026-11-11 10:00:00', 'America/Manaus'),
        $calendar,
    );

    expect($minutes)->toBe(360);
});

it('subtracts pause time from average resolution and first response using the policy calendar', function () {
    $admin = reportingAdministrator();
    $campus = Campus::factory()->create();
    $category = Category::factory()->create();
    $requester = User::factory()->type(UserType::Student)->create();
    $calendar = BusinessCalendar::factory()->create(['timezone' => 'America/Manaus']);
    $calendar->hours()->create(['weekday' => 1, 'segment' => 1, 'opens_at' => '09:00:00', 'closes_at' => '17:00:00']);
    $policy = SlaPolicy::factory()->create(['business_calendar_id' => $calendar->id]);
    $ticket = Ticket::factory()->create([
        'requester_id' => $requester->id, 'campus_id' => $campus->id, 'category_id' => $category->id,
        'sla_policy_id' => $policy->id, 'created_at' => '2026-11-09 09:00:00',
        'first_response_at' => '2026-11-09 09:30:00', 'resolved_at' => '2026-11-09 13:00:00',
        'status' => TicketStatus::Resolved->value, 'due_at' => '2026-11-09 17:00:00',
    ]);
    TicketSlaPause::factory()->create([
        'ticket_id' => $ticket->id, 'started_by' => $requester->id, 'ended_by' => $requester->id,
        'started_at' => '2026-11-09 10:00:00', 'ended_at' => '2026-11-09 11:00:00', 'duration_minutes' => 60,
    ]);

    $response = $this->actingAs($admin)->get(route('reports.index', [
        'date_from' => '2026-11-09', 'date_to' => '2026-11-09', 'campus_id' => $campus->id, 'category_id' => $category->id,
    ]))->assertOk();

    expect($response->viewData('durationMetrics')['average_resolution_minutes'])->toBe(180)
        ->and($response->viewData('durationMetrics')['average_first_response_minutes'])->toBe(30);
});

it('snapshots the responsible team and technician when a ticket is resolved', function () {
    $admin = reportingAdministrator();
    $campus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $technician = User::factory()->type(UserType::Technician)->create();
    $technician->assignRole('technician');
    $team->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    $ticket = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id, 'assigned_user_id' => $technician->id]);

    app(TicketWorkflow::class)->changeStatus($ticket, $admin, TicketStatus::InTriage);
    app(TicketWorkflow::class)->changeStatus($ticket, $admin, TicketStatus::InProgress);
    app(TicketWorkflow::class)->changeStatus($ticket, $admin, TicketStatus::Resolved);
    $resolution = $ticket->histories()->where('event_type', TicketAuditEvent::Resolved)->firstOrFail();

    expect($resolution->new_values['team_id'])->toBe($team->id)
        ->and($resolution->new_values['assigned_user_id'])->toBe($technician->id);
});
