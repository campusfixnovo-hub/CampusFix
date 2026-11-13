<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('completely disables public web registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Tentativa pública',
        'email' => 'public-signup@example.test',
        'user_type' => 'administrator',
        'role' => 'administrator',
        'password' => 'CampusFix2026Strong',
        'password_confirmation' => 'CampusFix2026Strong',
    ])->assertNotFound();

    expect(User::where('email', 'public-signup@example.test')->exists())->toBeFalse();
});

it('does not show a public account creation link on login and redirects the public entry point to login', function () {
    $this->get(route('login'))->assertOk()->assertDontSee('Criar cadastro');
    $this->get('/')->assertRedirect(route('login'));
});

it('does not expose registration through alternate API endpoints', function () {
    foreach (['/api/v1/auth/register', '/api/v1/register', '/api/register'] as $path) {
        $this->getJson($path)->assertNotFound();
        $this->postJson($path, [
            'name' => 'Tentativa pública',
            'email' => 'api-public-signup@example.test',
            'role' => 'administrator',
            'password' => 'CampusFix2026Strong',
        ])->assertNotFound();
    }

    expect(User::where('email', 'api-public-signup@example.test')->exists())->toBeFalse();
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
        'password' => 'NewCampusFix2026!',
        'password_confirmation' => 'NewCampusFix2026!',
    ])->assertRedirect(route('login'));

    expect(Hash::check('NewCampusFix2026!', $user->fresh()->password))->toBeTrue()
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
        'password' => 'NewCampusFix2026!',
        'password_confirmation' => 'NewCampusFix2026!',
    ])->assertSessionHasErrors('email');

    expect($user->tokens()->count())->toBe(1);
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.email', $user->email);
});
