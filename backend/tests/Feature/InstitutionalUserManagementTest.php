<?php

use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('requires authentication and administrator authorization to create an institutional account', function () {
    $this->post(route('admin.users.store'), [])->assertRedirect(route('login'));

    $requester = User::factory()->type(UserType::Student)->create();
    $requester->assignRole('student');

    $this->actingAs($requester)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($requester)->post(route('admin.users.store'), [
        'name' => 'Tentativa não autorizada',
        'email' => 'unauthorized@example.test',
        'role' => 'administrator',
        'password' => 'CampusFix2026Strong!',
        'password_confirmation' => 'CampusFix2026Strong!',
    ])->assertForbidden();

    expect(User::where('email', 'unauthorized@example.test')->exists())->toBeFalse();
});

it('allows an authorized administrator to create a user with the selected institutional role', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');

    $this->actingAs($administrator)->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Criar conta institucional')
        ->assertSee(route('admin.users.store'));

    $this->actingAs($administrator)->from(route('admin.users.index'))->post(route('admin.users.store'), [
        'name' => 'Técnica de Demonstração',
        'email' => 'TECH.DEMO@example.test',
        'phone' => '555-0101',
        'role' => 'technician',
        'user_type' => 'administrator',
        'is_active' => false,
        'password' => 'CampusFix2026Strong',
        'password_confirmation' => 'CampusFix2026Strong',
    ])->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'tech.demo@example.test')->firstOrFail();
    expect($created->user_type)->toBe(UserType::Technician)
        ->and($created->is_active)->toBeTrue()
        ->and($created->hasRole('technician'))->toBeTrue()
        ->and($created->hasRole('administrator'))->toBeFalse()
        ->and(Hash::check('CampusFix2026Strong!', $created->password))->toBeTrue();
});

it('rejects weak passwords and nonexistent institutional roles when an administrator creates a user', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');

    $this->actingAs($administrator)->from(route('admin.users.index'))->post(route('admin.users.store'), [
        'name' => 'Conta inválida',
        'email' => 'invalid-account@example.test',
        'role' => 'superuser',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])->assertSessionHasErrors(['role', 'password']);

    expect(User::where('email', 'invalid-account@example.test')->exists())->toBeFalse();
});

it('requires the same symbol-inclusive password policy as the first-admin command', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');

    $this->actingAs($administrator)->from(route('admin.users.index'))->post(route('admin.users.store'), [
        'name' => 'Conta sem símbolo',
        'email' => 'no-symbol@example.test',
        'role' => 'staff',
        'password' => 'CampusFix2026Strong',
        'password_confirmation' => 'CampusFix2026Strong',
    ])->assertSessionHasErrors('password');

    expect(User::where('email', 'no-symbol@example.test')->exists())->toBeFalse();
});

it('does not allow an inactive administrator to create accounts', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create(['is_active' => false]);
    $administrator->assignRole('administrator');

    $this->actingAs($administrator)->post(route('admin.users.store'), [
        'name' => 'Conta não criada',
        'email' => 'inactive-admin@example.test',
        'role' => 'student',
        'password' => 'CampusFix2026Strong!',
        'password_confirmation' => 'CampusFix2026Strong!',
    ])->assertRedirect(route('login'));

    expect(User::where('email', 'inactive-admin@example.test')->exists())->toBeFalse();
});

it('allows an authorized administrator to explicitly grant the administrator role to another account', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');

    $this->actingAs($administrator)->post(route('admin.users.store'), [
        'name' => 'Segundo administrador',
        'email' => 'second-admin@example.test',
        'role' => 'administrator',
        'password' => 'CampusFix2026Strong!',
        'password_confirmation' => 'CampusFix2026Strong!',
    ])->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'second-admin@example.test')->firstOrFail();
    expect($created->hasRole('administrator'))->toBeTrue()
        ->and($created->user_type)->toBe(UserType::Administrator);
});

it('prevents an administrator from changing their own role through the administrative interface', function () {
    $administrator = User::factory()->type(UserType::Administrator)->create();
    $administrator->assignRole('administrator');

    $this->actingAs($administrator)
        ->patch(route('admin.users.role', $administrator), ['role' => 'student'])
        ->assertForbidden();

    expect($administrator->fresh()->user_type)->toBe(UserType::Administrator)
        ->and($administrator->fresh()->hasRole('administrator'))->toBeTrue();
});
