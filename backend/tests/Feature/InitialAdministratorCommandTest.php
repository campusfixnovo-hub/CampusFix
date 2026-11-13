<?php

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the first administrator only from secure interactive prompts', function () {
    $this->artisan('campusfix:admin:create')
        ->expectsQuestion('Nome do administrador', 'CampusFix Administradora')
        ->expectsQuestion('E-mail do administrador', 'first.admin@example.test')
        ->expectsQuestion('Senha (mínimo 12 caracteres, letras, números e símbolo)', 'CampusFixAdmin2026!')
        ->expectsQuestion('Confirme a senha', 'CampusFixAdmin2026!')
        ->assertExitCode(0);

    $administrator = User::where('email', 'first.admin@example.test')->firstOrFail();

    expect($administrator->user_type)->toBe(UserType::Administrator)
        ->and($administrator->is_active)->toBeTrue()
        ->and($administrator->hasRole('administrator'))->toBeTrue()
        ->and(Hash::check('CampusFixAdmin2026!', $administrator->password))->toBeTrue();
});

it('refuses to create another initial administrator when one already exists', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');

    $this->artisan('campusfix:admin:create')->assertExitCode(1);

    expect(User::role('administrator')->count())->toBe(1);
});
