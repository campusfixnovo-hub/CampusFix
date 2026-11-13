<?php

use App\Enums\TicketStatus;
use App\Enums\UserType;
use App\Models\{Campus, Team, TeamHistory, Ticket, User};
use Illuminate\Support\Facades\DB;

function teamAdmin(): User
{
    $user = User::factory()->type(UserType::Administrator)->create();
    $user->assignRole('administrator');
    return $user;
}

function teamManager(): User
{
    $user = User::factory()->type(UserType::Manager)->create();
    $user->assignRole('manager');
    return $user;
}

function teamTechnician(): User
{
    $user = User::factory()->type(UserType::Technician)->create();
    $user->assignRole('technician');
    return $user;
}

it('creates and edits teams with a lead and durable membership audit', function () {
    $admin = teamAdmin();
    $campus = Campus::factory()->create();
    $first = teamTechnician();
    $second = teamTechnician();

    $this->actingAs($admin)->post(route('admin.teams.store'), [
        'name' => 'Infraestrutura', 'campus_id' => $campus->id,
        'technicians' => [$first->id, $second->id], 'lead_user_id' => $first->id, 'is_active' => 1,
    ])->assertRedirect();

    $team = Team::query()->where('name', 'Infraestrutura')->firstOrFail();
    expect($team->technicians()->count())->toBe(2)
        ->and($team->leader()->first()->id)->toBe($first->id)
        ->and($team->histories()->where('event_type', 'team_created')->exists())->toBeTrue()
        ->and($team->histories()->where('event_type', 'member_added')->count())->toBe(2);

    $this->actingAs($admin)->put(route('admin.teams.update', $team), [
        'name' => 'Infraestrutura predial', 'campus_id' => $campus->id,
        'technicians' => [$second->id], 'lead_user_id' => $second->id, 'is_active' => 1,
    ])->assertRedirect();

    expect($team->fresh()->name)->toBe('Infraestrutura predial')
        ->and(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $first->id)->value('member_role'))->toBe('former_technician')
        ->and($team->histories()->where('event_type', 'member_removed')->where('subject_user_id', $first->id)->exists())->toBeTrue()
        ->and($team->histories()->where('event_type', 'team_updated')->exists())->toBeTrue();
});

it('rejects inactive or non-technician team members and duplicate submissions', function () {
    $admin = teamAdmin();
    $campus = Campus::factory()->create();
    $inactive = teamTechnician();
    $inactive->forceFill(['is_active' => false])->save();
    $student = User::factory()->type(UserType::Student)->create();
    $student->assignRole('student');
    $valid = teamTechnician();

    $this->actingAs($admin)->from(route('admin.teams.create'))->post(route('admin.teams.store'), [
        'name' => 'Inválida', 'campus_id' => $campus->id,
        'technicians' => [$valid->id, $valid->id], 'lead_user_id' => $valid->id,
    ])->assertSessionHasErrors(['technicians.0', 'technicians.1']);

    $this->actingAs($admin)->from(route('admin.teams.create'))->post(route('admin.teams.store'), [
        'name' => 'Inválida', 'campus_id' => $campus->id,
        'technicians' => [$inactive->id], 'lead_user_id' => $inactive->id,
    ])->assertSessionHasErrors('technicians');

    $this->actingAs($admin)->from(route('admin.teams.create'))->post(route('admin.teams.store'), [
        'name' => 'Inválida', 'campus_id' => $campus->id,
        'technicians' => [$student->id], 'lead_user_id' => $student->id,
    ])->assertSessionHasErrors('technicians');
    expect(Team::query()->where('name', 'Inválida')->exists())->toBeFalse();
});

it('allows a technician to belong to multiple teams without duplicate links', function () {
    $admin = teamAdmin();
    $campus = Campus::factory()->create();
    $technician = teamTechnician();
    $first = Team::factory()->for($campus)->create();
    $second = Team::factory()->for($campus)->create();

    foreach ([$first, $second] as $team) {
        $this->actingAs($admin)->put(route('admin.teams.update', $team), [
            'name' => $team->name, 'campus_id' => $campus->id,
            'technicians' => [$technician->id], 'lead_user_id' => $technician->id, 'is_active' => 1,
        ])->assertRedirect();
    }

    expect($technician->teams()->count())->toBe(2)
        ->and(DB::table('team_members')->where('user_id', $technician->id)->count())->toBe(2);
});

it('limits managers to their teams and blocks solicitants from administrative workload', function () {
    $manager = teamManager();
    $campus = Campus::factory()->create();
    $ownTeam = Team::factory()->for($campus)->create();
    $otherTeam = Team::factory()->create();
    $ownTeam->members()->attach($manager->id, ['member_role' => 'manager', 'joined_at' => now()]);

    $this->actingAs($manager)->get(route('manager.teams.index'))->assertOk()->assertSee($ownTeam->name)->assertDontSee($otherTeam->name);
    $this->actingAs($manager)->get(route('manager.teams.edit', $otherTeam))->assertForbidden();
    $this->actingAs($manager)->put(route('manager.teams.update', $otherTeam), ['name' => 'Sem acesso'])->assertForbidden();

    $student = User::factory()->type(UserType::Student)->create();
    $student->assignRole('student');
    $this->actingAs($student)->get(route('teams.workload'))->assertForbidden();
});

it('archives technicians on deactivation and preserves assigned ticket history', function () {
    $admin = teamAdmin();
    $campus = Campus::factory()->create();
    $technician = teamTechnician();
    $team = Team::factory()->for($campus)->create();
    $team->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    $ticket = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id, 'assigned_user_id' => $technician->id]);

    $this->actingAs($admin)->patch(route('admin.users.active', $technician), ['is_active' => false])->assertRedirect();

    expect($ticket->fresh()->assigned_user_id)->toBe($technician->id)
        ->and(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $technician->id)->value('member_role'))->toBe('former_technician')
        ->and($team->histories()->where('event_type', 'technician_deactivated')->where('subject_user_id', $technician->id)->exists())->toBeTrue();
});

it('deactivates teams without deleting memberships or historic tickets', function () {
    $admin = teamAdmin();
    $campus = Campus::factory()->create();
    $technician = teamTechnician();
    $team = Team::factory()->for($campus)->create();
    $team->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    $ticket = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id, 'assigned_user_id' => $technician->id]);

    $this->actingAs($admin)->delete(route('admin.teams.destroy', $team))->assertRedirect();

    expect($team->fresh()->is_active)->toBeFalse()
        ->and($ticket->fresh())->not->toBeNull()
        ->and(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $technician->id)->exists())->toBeTrue()
        ->and($team->histories()->where('event_type', 'team_deactivated')->exists())->toBeTrue();
});

it('reports scoped workload including unassigned tickets and technicians without assignments', function () {
    $manager = teamManager();
    $campus = Campus::factory()->create();
    $outsideCampus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $tech = teamTechnician();
    $idle = teamTechnician();
    $team->members()->attach($manager->id, ['member_role' => 'manager', 'joined_at' => now()]);
    $team->members()->attach($tech->id, ['member_role' => 'lead', 'joined_at' => now()]);
    $team->members()->attach($idle->id, ['member_role' => 'technician', 'joined_at' => now()]);
    $assigned = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id, 'assigned_user_id' => $tech->id, 'status' => TicketStatus::InProgress->value, 'priority' => 'critical']);
    $unassigned = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id, 'assigned_user_id' => null]);
    $outside = Ticket::factory()->create(['campus_id' => $outsideCampus->id]);

    $this->actingAs($manager)->get(route('teams.workload'))->assertOk()
        ->assertSee($assigned->code)->assertSee($unassigned->code)->assertDontSee($outside->code)
        ->assertSee($idle->name)->assertSee('aguardam atribuição');

    $this->actingAs($manager)->get(route('teams.workload', [
        'team_id' => $team->id, 'technician_id' => $tech->id,
        'priority' => 'critical', 'status' => TicketStatus::InProgress->value,
    ]))->assertOk()->assertSee($assigned->code)->assertDontSee($unassigned->code);
});
