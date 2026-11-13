<?php

use App\Enums\UserType;
use App\Models\Campus;
use App\Models\Comment;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('limits requesters to their own tickets', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $other = User::factory()->type(UserType::Staff)->create();
    $requester->assignRole('student');
    $other->assignRole('staff');
    $ownTicket = Ticket::factory()->create(['requester_id' => $requester->id]);
    $otherTicket = Ticket::factory()->create(['requester_id' => $other->id]);

    expect(Gate::forUser($requester)->allows('view', $ownTicket))->toBeTrue()
        ->and(Gate::forUser($requester)->allows('view', $otherTicket))->toBeFalse();
});

it('allows technicians to view assigned and team tickets but not unrelated tickets', function () {
    $campus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $technician = User::factory()->type(UserType::Technician)->create();
    $technician->assignRole('technician');
    $team->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);

    $assigned = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id, 'assigned_user_id' => $technician->id]);
    $teamTicket = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id]);
    $unrelated = Ticket::factory()->create();

    expect(Gate::forUser($technician)->allows('view', $assigned))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('view', $teamTicket))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('view', $unrelated))->toBeFalse();
});

it('limits managers to the campus and teams in their membership scope', function () {
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $team = Team::factory()->for($campus)->create();
    $sameCampusOtherTeam = Team::factory()->for($campus)->create();
    $manager = User::factory()->type(UserType::Manager)->create();
    $manager->assignRole('manager');
    $team->members()->attach($manager->id, ['member_role' => 'manager', 'joined_at' => now()]);

    $teamTicket = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $team->id]);
    $campusTicket = Ticket::factory()->create(['campus_id' => $campus->id, 'team_id' => $sameCampusOtherTeam->id]);
    $outOfScope = Ticket::factory()->create(['campus_id' => $otherCampus->id]);

    expect(Gate::forUser($manager)->allows('view', $teamTicket))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('view', $campusTicket))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('view', $outOfScope))->toBeFalse();
});

it('allows administrators to view tickets across all campuses', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');
    $ticket = Ticket::factory()->create();

    expect(Gate::forUser($administrator)->allows('view', $ticket))->toBeTrue();
});

it('hides internal notes from requesters while allowing public comments', function () {
    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);
    $publicComment = Comment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $requester->id, 'is_internal' => false]);
    $internalComment = Comment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $requester->id, 'is_internal' => true]);

    expect(Gate::forUser($requester)->allows('view', $publicComment))->toBeTrue()
        ->and(Gate::forUser($requester)->allows('view', $internalComment))->toBeFalse();
});

it('allows only administrators to change another account role and forbids self changes', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');
    $target = User::factory()->create();
    $regular = User::factory()->type(UserType::Student)->create();
    $regular->assignRole('student');

    $this->actingAs($regular)->patch(route('admin.users.role', $target), ['role' => 'technician'])->assertForbidden();
    $this->actingAs($administrator)->patch(route('admin.users.role', $administrator), ['role' => 'staff'])->assertForbidden();

    $this->from(route('home'))->actingAs($administrator)
        ->patch(route('admin.users.role', $target), ['role' => 'technician'])
        ->assertRedirect(route('home'));

    expect($target->fresh()->user_type)->toBe(UserType::Technician)
        ->and($target->fresh()->hasRole('technician'))->toBeTrue();
});

it('blocks inactive accounts from policy-authorized resources', function () {
    $user = User::factory()->type(UserType::Student)->create(['is_active' => false]);
    $user->assignRole('student');
    $ticket = Ticket::factory()->create(['requester_id' => $user->id]);

    expect(Gate::forUser($user)->allows('view', $ticket))->toBeFalse();
});
