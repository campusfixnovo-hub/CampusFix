<?php

use App\Enums\{TicketPriority, TicketStatus, UserType};
use App\Models\{Building, BusinessCalendar, Campus, Category, Location, SlaPolicy, Team, Ticket, TicketHistory, User};
use App\Services\{SlaClock, TicketWorkflow};
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function campusfixAdministrator(): User
{
    $admin = User::factory()->type(UserType::Administrator)->create();
    $admin->assignRole('administrator');
    return $admin;
}

it('enforces the allowed lifecycle and records state changes, resolution, closure and reopening', function () {
    $admin = campusfixAdministrator();
    $ticket = Ticket::factory()->create();
    $workflow = app(TicketWorkflow::class);

    $workflow->changeStatus($ticket, $admin, TicketStatus::InTriage);
    $workflow->changeStatus($ticket, $admin, TicketStatus::AwaitingService);
    $workflow->changeStatus($ticket, $admin, TicketStatus::InProgress);
    expect($ticket->fresh()->first_response_at)->not->toBeNull();
    $workflow->changeStatus($ticket, $admin, TicketStatus::AwaitingMaterial);
    expect($ticket->fresh()->slaPauses()->count())->toBe(0);
    $workflow->changeStatus($ticket, $admin, TicketStatus::InProgress);
    $workflow->changeStatus($ticket, $admin, TicketStatus::Resolved);
    $workflow->changeStatus($ticket, $admin, TicketStatus::Closed);
    expect($ticket->fresh()->closed_at)->not->toBeNull();
    $workflow->changeStatus($ticket, $admin, TicketStatus::Reopened);
    expect($ticket->fresh()->closed_at)->toBeNull()
        ->and($ticket->fresh()->resolved_at)->toBeNull()
        ->and($ticket->histories()->where('event_type', 'resolved')->exists())->toBeTrue()
        ->and($ticket->histories()->where('event_type', 'closed')->exists())->toBeTrue()
        ->and($ticket->histories()->where('event_type', 'reopened')->exists())->toBeTrue();

    expect(fn () => $workflow->changeStatus($ticket, $admin, TicketStatus::Open))
        ->toThrow(ValidationException::class);
});

it('allows a requester to cancel only before service has begun and audits the cancellation', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);
    $workflow = app(TicketWorkflow::class);

    $workflow->changeStatus($ticket, $requester, TicketStatus::Cancelled);
    expect($ticket->fresh()->status)->toBe(TicketStatus::Cancelled)
        ->and($ticket->histories()->where('event_type', 'cancelled')->where('actor_id', $requester->id)->exists())->toBeTrue();

    $another = Ticket::factory()->create(['requester_id' => $requester->id, 'status' => TicketStatus::InProgress]);
    expect(fn () => $workflow->changeStatus($another, $requester, TicketStatus::Cancelled))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('requires an active compatible team and active team member technician for assignment and audits reassignments', function () {
    $admin = campusfixAdministrator();
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $wrongCampusTeam = Team::factory()->for($otherCampus)->create();
    $technician = User::factory()->type(UserType::Technician)->create();
    $technician->assignRole('technician');
    $team->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    $ticket = Ticket::factory()->create(['campus_id' => $campus->id]);
    $workflow = app(TicketWorkflow::class);

    expect(fn () => $workflow->assign($ticket, $admin, $wrongCampusTeam->id, null))
        ->toThrow(ValidationException::class);
    $workflow->assign($ticket, $admin, $team->id, $technician->id);
    expect($ticket->fresh()->team_id)->toBe($team->id)
        ->and($ticket->fresh()->assigned_user_id)->toBe($technician->id)
        ->and($ticket->histories()->where('event_type', 'team_assigned')->exists())->toBeTrue()
        ->and($ticket->histories()->where('event_type', 'technician_assigned')->exists())->toBeTrue();

    $replacementTeam = Team::factory()->for($campus)->create();
    $replacementTechnician = User::factory()->type(UserType::Technician)->create();
    $replacementTechnician->assignRole('technician');
    $replacementTeam->members()->attach($replacementTechnician->id, ['member_role'=>'technician','joined_at'=>now()]);
    $workflow->assign($ticket, $admin, $replacementTeam->id, $replacementTechnician->id);
    $latestTeamEvent = $ticket->histories()->where('event_type','team_assigned')->reorder()->orderByDesc('id')->firstOrFail();
    expect($ticket->fresh()->team_id)->toBe($replacementTeam->id)
        ->and($ticket->fresh()->assigned_user_id)->toBe($replacementTechnician->id)
        ->and($latestTeamEvent->old_values['team_id'])->toBe($team->id)
        ->and($latestTeamEvent->new_values['team_id'])->toBe($replacementTeam->id);

    $manager = User::factory()->type(UserType::Manager)->create();
    $manager->assignRole('manager');
    $team->members()->attach($manager->id, ['member_role'=>'manager','joined_at'=>now()]);
    expect($manager->can('assign',$ticket))->toBeTrue();
    $outOfScope = Ticket::factory()->create(['campus_id'=>$otherCampus->id]);
    expect($manager->can('assign',$outOfScope))->toBeFalse();

    $inactiveTeam = Team::factory()->for($campus)->create(['is_active' => false]);
    expect(fn () => $workflow->assign($ticket, $admin, $inactiveTeam->id, null))
        ->toThrow(ValidationException::class);

    $inactiveTechnician = User::factory()->type(UserType::Technician)->create(['is_active' => false]);
    $inactiveTechnician->assignRole('technician');
    $team->members()->attach($inactiveTechnician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    expect(fn () => $workflow->assign($ticket, $admin, $team->id, $inactiveTechnician->id))
        ->toThrow(ValidationException::class);
});

it('preserves old SLA values in the audit when a priority changes and rejects unauthorized operations', function () {
    $admin = campusfixAdministrator();
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id, 'priority'=>'medium']);
    $workflow = app(TicketWorkflow::class);

    expect(fn () => $workflow->changeStatus($ticket, $requester, TicketStatus::InProgress))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    expect(fn () => $workflow->assign($ticket, $requester, null, null))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $oldDue = $ticket->due_at;
    $workflow->changePriority($ticket, $admin, TicketPriority::High);
    $event = $ticket->histories()->where('event_type', 'priority_changed')->firstOrFail();
    expect($event->actor_id)->toBe($admin->id)
        ->and($event->old_values['priority'])->toBe('medium')
        ->and($event->old_values['due_at'])->toBe($oldDue->toISOString())
        ->and($event->new_values['priority'])->toBe('high')
        ->and($event->new_values['sla_target_minutes'])->toBe(720)
        ->and($event->created_at)->not->toBeNull();
});

it('validates campus, building and location together and audits a location change', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $ticket = Ticket::factory()->create(['requester_id'=>$requester->id]);
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $building = Building::factory()->for($campus)->create();
    $otherBuilding = Building::factory()->for($otherCampus)->create();
    $location = Location::factory()->for($building)->create();
    $workflow = app(TicketWorkflow::class);

    expect(fn () => $workflow->changeLocation($ticket,$requester,$campus->id,$otherBuilding->id,null,'Bloco B'))
        ->toThrow(ValidationException::class);
    $workflow->changeLocation($ticket,$requester,$campus->id,$building->id,$location->id,'Sala 12');
    $event=$ticket->histories()->where('event_type','location_changed')->firstOrFail();
    expect($ticket->fresh()->location_id)->toBe($location->id)
        ->and($event->actor_id)->toBe($requester->id)
        ->and($event->old_values['location_id'])->toBeNull()
        ->and($event->new_values['location_id'])->toBe($location->id)
        ->and($event->new_values['location_details'])->toBe('Sala 12');
});

it('pauses only while awaiting the requester and resumes by the business minutes actually paused', function () {
    $admin = campusfixAdministrator();
    $now = CarbonImmutable::parse('2026-11-09 10:00:00', 'America/Manaus');
    CarbonImmutable::setTestNow($now);
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $policy = SlaPolicy::where('priority', TicketPriority::Medium->value)->whereNull('category_id')->firstOrFail();
    $due = app(SlaClock::class)->addBusinessMinutes($now, 240, $calendar);
    $ticket = Ticket::factory()->create(['sla_policy_id' => $policy->id, 'sla_target_minutes' => 240, 'due_at' => $due]);
    $workflow = app(TicketWorkflow::class);

    $workflow->changeStatus($ticket, $admin, TicketStatus::InTriage);
    $workflow->changeStatus($ticket, $admin, TicketStatus::AwaitingService);
    $workflow->changeStatus($ticket, $admin, TicketStatus::InProgress);
    $workflow->changeStatus($ticket, $admin, TicketStatus::AwaitingMaterial);
    expect($ticket->fresh()->slaPauses()->count())->toBe(0);
    $workflow->changeStatus($ticket, $admin, TicketStatus::InProgress);
    $workflow->changeStatus($ticket, $admin, TicketStatus::AwaitingRequester);
    $dueBeforeResume = $ticket->fresh()->due_at;

    CarbonImmutable::setTestNow($now->addHour());
    $workflow->changeStatus($ticket, $admin, TicketStatus::InProgress);
    $pause = $ticket->slaPauses()->firstOrFail();
    expect($pause->duration_minutes)->toBe(60)
        ->and($pause->started_by)->toBe($admin->id)
        ->and($pause->ended_by)->toBe($admin->id)
        ->and($ticket->fresh()->due_at->timestamp)->toBeGreaterThan($dueBeforeResume->timestamp)
        ->and($ticket->histories()->where('event_type', 'sla_paused')->exists())->toBeTrue()
        ->and($ticket->histories()->where('event_type', 'sla_resumed')->exists())->toBeTrue();
    CarbonImmutable::setTestNow();
});

it('counts calendar segments and skips institutional holidays when calculating due times', function () {
    $calendar = BusinessCalendar::factory()->create();
    $calendar->hours()->create(['weekday'=>1,'segment'=>1,'opens_at'=>'08:00','closes_at'=>'12:00']);
    $calendar->hours()->create(['weekday'=>1,'segment'=>2,'opens_at'=>'13:00','closes_at'=>'17:00']);
    $calendar->hours()->create(['weekday'=>2,'segment'=>1,'opens_at'=>'08:00','closes_at'=>'17:00']);
    $calendar->hours()->create(['weekday'=>3,'segment'=>1,'opens_at'=>'08:00','closes_at'=>'17:00']);
    $calendar->holidays()->create(['holiday_date'=>'2026-11-10','name'=>'Feriado institucional','is_working_day'=>false]);
    $clock = app(SlaClock::class);

    $endOfDay = $clock->addBusinessMinutes(CarbonImmutable::parse('2026-11-09 11:59','America/Manaus'),3,$calendar);
    expect($endOfDay->format('Y-m-d H:i'))->toBe('2026-11-09 13:02')
        ->and($clock->businessMinutesBetween(CarbonImmutable::parse('2026-11-09 11:59','America/Manaus'),$endOfDay,$calendar))->toBe(3);

    $afterHoliday = $clock->addBusinessMinutes(CarbonImmutable::parse('2026-11-09 16:59','America/Manaus'),2,$calendar);
    expect($afterHoliday->format('Y-m-d H:i'))->toBe('2026-11-11 08:01');
});

it('shows business-calendar deadlines that are near expiry and hides paused tickets from the overdue filter', function () {
    $admin = campusfixAdministrator();
    $policy = SlaPolicy::where('priority',TicketPriority::Medium->value)->whereNull('category_id')->firstOrFail();
    $soonDue = app(SlaClock::class)->addBusinessMinutes(CarbonImmutable::now(),30,$policy->calendar);
    $soon = Ticket::factory()->create(['sla_policy_id'=>$policy->id,'sla_target_minutes'=>$policy->target_minutes,'due_at'=>$soonDue,'priority'=>'medium']);
    $paused = Ticket::factory()->create(['sla_policy_id'=>$policy->id,'status'=>TicketStatus::AwaitingRequester,'due_at'=>now()->subHour(),'priority'=>'medium']);
    $paused->slaPauses()->create(['started_by'=>$admin->id,'start_reason'=>'awaiting_requester','started_at'=>now()->subMinutes(30)]);

    $this->actingAs($admin)->get(route('tickets.index',['sla'=>'soon']))->assertOk()->assertSee($soon->code);
    $this->actingAs($admin)->get(route('tickets.index',['sla'=>'overdue']))->assertOk()->assertDontSee($paused->code);
});

it('protects internal comments and serves private attachments only to users who can view the ticket', function () {
    Storage::fake('local');
    $owner = User::factory()->type(UserType::Student)->create();
    $owner->assignRole('student');
    $other = User::factory()->type(UserType::Staff)->create();
    $other->assignRole('staff');
    $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);
    $admin = campusfixAdministrator();
    $internal = app(TicketWorkflow::class)->comment($ticket, $admin, 'Nota operacional', true);
    $commentEvent = $ticket->histories()->where('event_type','comment_added')->firstOrFail();
    expect($owner->can('view', $internal))->toBeFalse();
    expect($commentEvent->actor_id)->toBe($admin->id)
        ->and($commentEvent->new_values['internal'])->toBeTrue()
        ->and($commentEvent->created_at)->not->toBeNull();

    $category = Category::where('is_active', true)->firstOrFail();
    $campus = Campus::where('is_active', true)->firstOrFail();
    $this->actingAs($owner)->post(route('tickets.store'), [
        'title' => 'Documento de apoio', 'description' => 'Solicito análise do documento.',
        'category_id' => $category->id, 'priority' => 'medium', 'campus_id' => $campus->id,
        'attachments' => [UploadedFile::fake()->create('comprovante.pdf', 20, 'application/pdf')],
    ])->assertRedirect();
    $attachment = \App\Models\Attachment::query()->latest('id')->firstOrFail();
    expect($attachment->disk)->toBe('local')->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
    expect(TicketHistory::where('ticket_id',$attachment->ticket_id)->where('event_type','attachment_added')->where('actor_id',$owner->id)->exists())->toBeTrue();
    $this->actingAs($owner)->get(route('attachments.show', $attachment))->assertOk();
    $this->actingAs($other)->get(route('attachments.show', $attachment))->assertForbidden();
});

it('rejects unsupported or oversized attachments before creating a ticket', function () {
    $owner = User::factory()->type(UserType::Student)->create();
    $owner->assignRole('student');
    $category = Category::where('is_active', true)->firstOrFail();
    $campus = Campus::where('is_active', true)->firstOrFail();
    $data = ['title'=>'Arquivo inválido','description'=>'Teste de validação.','category_id'=>$category->id,'priority'=>'medium','campus_id'=>$campus->id];

    $this->actingAs($owner)->post(route('tickets.store'), $data + ['attachments'=>[UploadedFile::fake()->create('script.exe', 20, 'application/x-msdownload')]])
        ->assertSessionHasErrors('attachments.0');
    $this->actingAs($owner)->post(route('tickets.store'), $data + ['attachments'=>[UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf')]])
        ->assertSessionHasErrors('attachments.0');
    expect(Ticket::where('requester_id',$owner->id)->count())->toBe(0);
});
