<?php

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('registers a public student and derives its role from the allowlisted profile', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ana Costa',
        'email' => 'ANA.COSTA@example.test',
        'user_type' => 'student',
        'role' => 'administrator',
        'password' => 'CampusFix2026Strong',
        'password_confirmation' => 'CampusFix2026Strong',
    ]);

    $response->assertRedirect(route('home'));
    $user = User::where('email', 'ana.costa@example.test')->firstOrFail();

    expect($user->user_type)->toBe(UserType::Student)
        ->and($user->hasRole('student'))->toBeTrue()
        ->and($user->hasRole('administrator'))->toBeFalse()
        ->and(Hash::check('CampusFix2026Strong', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('shows the public registration password length requirement in the form', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('name="password"', false)
        ->assertSee('name="password" type="password" autocomplete="new-password" minlength="12" required', false)
        ->assertSee('name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required', false);
});

it('returns a Portuguese minimum length message for a short registration password', function () {
    $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Pessoa de Teste',
        'email' => 'password-short@example.test',
        'user_type' => 'student',
        'password' => 'Abcdef12345',
        'password_confirmation' => 'Abcdef12345',
    ])->assertSessionHasErrors(['password' => 'A senha deve ter pelo menos 12 caracteres.']);

    expect(User::where('email', 'password-short@example.test')->exists())->toBeFalse();
});

it('returns a Portuguese confirmation message when registration passwords do not match', function () {
    $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Pessoa de Teste',
        'email' => 'password-mismatch@example.test',
        'user_type' => 'student',
        'password' => 'CampusFix2026Strong',
        'password_confirmation' => 'CampusFix2026Wrong',
    ])->assertSessionHasErrors(['password' => 'A confirmação da senha não corresponde.']);

    expect(User::where('email', 'password-mismatch@example.test')->exists())->toBeFalse();
});

it('rejects privileged profiles on public registration', function () {
    $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Pessoa Admin',
        'email' => 'privileged@example.test',
        'user_type' => 'administrator',
        'password' => 'CampusFix2026Strong',
        'password_confirmation' => 'CampusFix2026Strong',
    ])->assertSessionHasErrors('user_type');

    expect(User::where('email', 'privileged@example.test')->exists())->toBeFalse();
});

it('authenticates active users and rejects inactive accounts', function () {
    $active = User::factory()->create(['email' => 'active@example.test', 'password' => 'CampusFix2026Strong']);
    $inactive = User::factory()->create(['email' => 'inactive@example.test', 'password' => 'CampusFix2026Strong', 'is_active' => false]);

    $this->post(route('login.store'), ['email' => $active->email, 'password' => 'CampusFix2026Strong'])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($active);

    auth()->logout();
    $this->post(route('login.store'), ['email' => $inactive->email, 'password' => 'CampusFix2026Strong'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('logs out an already authenticated user when the account becomes inactive', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertOk();
    $user->forceFill(['is_active' => false])->save();

    $this->get(route('home'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('sends a password reset link and accepts the issued token', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'reset@example.test']);
    $otherUser = User::factory()->create(['email' => 'other-reset@example.test']);
    $firstMobileToken = $user->createToken('Android one')->plainTextToken;
    $secondMobileToken = $user->createToken('Android two')->plainTextToken;
    $otherMobileToken = $otherUser->createToken('Other account')->plainTextToken;

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewCampusFix2026',
        'password_confirmation' => 'NewCampusFix2026',
    ])->assertRedirect(route('login'));

    expect(Hash::check('NewCampusFix2026', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and($otherUser->tokens()->count())->toBe(1);

    auth()->forgetGuards();
    $this->withToken($firstMobileToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    auth()->forgetGuards();
    $this->withToken($secondMobileToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    auth()->forgetGuards();
    $this->withToken($otherMobileToken)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.email', $otherUser->email);
});

it('preserves all account tokens when a password reset attempt is invalid', function () {
    $user = User::factory()->create(['email' => 'invalid-reset@example.test']);
    $token = $user->createToken('Android current')->plainTextToken;

    $this->post(route('password.store'), [
        'token' => 'invalid-reset-token',
        'email' => $user->email,
        'password' => 'NewCampusFix2026',
        'password_confirmation' => 'NewCampusFix2026',
    ])->assertSessionHasErrors('email');

    expect($user->tokens()->count())->toBe(1);
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.email', $user->email);
});
