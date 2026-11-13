<?php

use App\Enums\UserType;
use App\Models\Campus;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function demoCommandPasswords(): array
{
    $newPassword = static function (): string {
        $characters = chr(random_int(65, 90))
            .chr(random_int(97, 122))
            .random_int(0, 9)
            .'!'
            .bin2hex(random_bytes(8));

        return str_shuffle($characters);
    };

    return [
        'campusfix.demo.admin@example.test' => $newPassword(),
        'campusfix.demo.manager@example.test' => $newPassword(),
        'campusfix.demo.technician1@example.test' => $newPassword(),
        'campusfix.demo.technician2@example.test' => $newPassword(),
    ];
}

it('creates four demo roles and an audited campus team using hidden password prompts', function () {
    $account = User::factory()->type(UserType::Student)->create();
    $account->assignRole('student');
    $campus = Campus::query()->where('is_active', true)->firstOrFail();
    $passwords = demoCommandPasswords();
    $command = $this->artisan('campusfix:demo-users');

    foreach ($passwords as $email => $password) {
        $command->expectsQuestion("Senha para {$email} (mínimo 12 caracteres, maiúsculas, minúsculas, números e símbolos)", $password)
            ->expectsQuestion("Confirme a senha para {$email}", $password)
            ->doesntExpectOutputToContain($password);
    }

    $command->assertExitCode(0)->run();

    $admin = User::where('email', 'campusfix.demo.admin@example.test')->firstOrFail();
    $manager = User::where('email', 'campusfix.demo.manager@example.test')->firstOrFail();
    $technician1 = User::where('email', 'campusfix.demo.technician1@example.test')->firstOrFail();
    $technician2 = User::where('email', 'campusfix.demo.technician2@example.test')->firstOrFail();
    $team = Team::query()->where('name', 'Equipe de Manutenção Geral')->firstOrFail();

    expect($admin->user_type)->toBe(UserType::Administrator)
        ->and($admin->hasRole('administrator'))->toBeTrue()
        ->and($admin->can('users.manage'))->toBeTrue()
        ->and($admin->can('roles.manage'))->toBeTrue()
        ->and($admin->can('teams.manage'))->toBeTrue()
        ->and($admin->can('catalog.manage'))->toBeTrue()
        ->and($admin->can('sla.manage'))->toBeTrue()
        ->and($admin->can('reports.view-all'))->toBeTrue()
        ->and($manager->user_type)->toBe(UserType::Manager)
        ->and($manager->hasRole('manager'))->toBeTrue()
        ->and($manager->can('tickets.assign'))->toBeTrue()
        ->and($manager->teams()->whereKey($team->id)->exists())->toBeTrue()
        ->and($technician1->hasRole('technician'))->toBeTrue()
        ->and($technician1->can('tickets.view-assigned'))->toBeTrue()
        ->and($technician2->hasRole('technician'))->toBeTrue()
        ->and($technician1->teams()->whereKey($team->id)->exists())->toBeTrue()
        ->and($technician2->teams()->whereKey($team->id)->exists())->toBeTrue()
        ->and(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $manager->id)->value('member_role'))->toBe('manager')
        ->and($team->campus_id)->toBe($campus->id)
        ->and($team->histories()->where('event_type', 'team_created')->exists())->toBeTrue()
        ->and($team->histories()->where('event_type', 'member_added')->count())->toBe(3)
        ->and($account->fresh()->hasRole('student'))->toBeTrue()
        ->and($account->fresh()->user_type)->toBe(UserType::Student);

    foreach ($passwords as $email => $password) {
        $demoUser = User::where('email', $email)->firstOrFail();
        expect(Hash::check($password, $demoUser->password))->toBeTrue()
            ->and($demoUser->password)->not->toBe($password);
    }

    $ticket = Ticket::factory()->create(['campus_id' => $campus->id]);
    app(TicketWorkflow::class)->assign($ticket, $manager, $team->id, $technician1->id);
    expect($ticket->fresh()->team_id)->toBe($team->id)
        ->and($ticket->fresh()->assigned_user_id)->toBe($technician1->id);

    $csrfToken = str_repeat('a', 40);
    $this->actingAs($account)->withSession(['_token' => $csrfToken])->post(route('tickets.assignment', $ticket), [
        '_token' => $csrfToken,
        'team_id' => $team->id,
        'assigned_user_id' => $technician2->id,
    ])->assertForbidden();
});

it('is idempotent and does not request or reset passwords for existing demo accounts', function () {
    $passwords = demoCommandPasswords();
    $command = $this->artisan('campusfix:demo-users');
    foreach ($passwords as $email => $password) {
        $command->expectsQuestion("Senha para {$email} (mínimo 12 caracteres, maiúsculas, minúsculas, números e símbolos)", $password)
            ->expectsQuestion("Confirme a senha para {$email}", $password);
    }
    $command->assertExitCode(0)->run();

    $team = Team::where('name', 'Equipe de Manutenção Geral')->firstOrFail();
    $usersBefore = User::whereIn('email', array_keys($passwords))->get()->keyBy('email');
    $historyCount = $team->histories()->count();
    $secondRun = $this->artisan('campusfix:demo-users')->assertExitCode(0);
    $secondRun->run();
    $usersAfter = User::whereIn('email', array_keys($passwords))->get()->keyBy('email');

    expect(User::whereIn('email', array_keys($passwords))->count())->toBe(4)
        ->and(Team::where('name', 'Equipe de Manutenção Geral')->count())->toBe(1)
        ->and($team->fresh()->histories()->count())->toBe($historyCount);

    foreach ($passwords as $email => $password) {
        expect($usersAfter[$email]->password)->toBe($usersBefore[$email]->password)
            ->and(Hash::check($password, $usersAfter[$email]->password))->toBeTrue();
    }
});

it('refuses execution outside local without touching users or teams', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $userCount = User::count();
    $teamCount = Team::count();

    $command = $this->artisan('campusfix:demo-users')->assertExitCode(1);
    $command->run();

    expect(User::count())->toBe($userCount)
        ->and(Team::count())->toBe($teamCount);
});

it('refuses to reuse a reserved demo email with an unrelated existing account', function () {
    $existing = User::factory()->create(['email' => 'campusfix.demo.admin@example.test']);
    $existing->assignRole('student');
    $originalPasswordHash = $existing->password;

    $this->artisan('campusfix:demo-users')->assertExitCode(1)->run();

    expect(User::where('email', 'campusfix.demo.admin@example.test')->count())->toBe(1)
        ->and($existing->fresh()->user_type)->toBe(UserType::Student)
        ->and($existing->fresh()->hasRole('student'))->toBeTrue()
        ->and($existing->fresh()->password)->toBe($originalPasswordHash)
        ->and(Team::where('name', 'Equipe de Manutenção Geral')->exists())->toBeFalse();
});

it('rejects weak or mismatched passwords before creating any demo records', function () {
    $email = 'campusfix.demo.admin@example.test';

    $this->artisan('campusfix:demo-users')
        ->expectsQuestion("Senha para {$email} (mínimo 12 caracteres, maiúsculas, minúsculas, números e símbolos)", 'weak')
        ->expectsQuestion("Confirme a senha para {$email}", 'weak')
        ->assertExitCode(1)
        ->run();

    expect(User::where('email', $email)->exists())->toBeFalse()
        ->and(Team::where('name', 'Equipe de Manutenção Geral')->exists())->toBeFalse();
});
