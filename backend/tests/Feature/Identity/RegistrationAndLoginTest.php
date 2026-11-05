<?php

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Identity\Domain\User;

it('registers a user, sends verification email and starts a session', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Nguyễn Văn A',
        'email' => 'A@Example.com',
        'password' => 'Secret12345',
        'password_confirmation' => 'Secret12345',
    ])->assertCreated();

    $user = User::query()->where('email', 'a@example.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->platform_role)->toBeNull()
        ->and($user->locale)->toBe('vi');

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'a@example.com')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.workspaces', []);

    expect(AuditLog::query()->where('action', 'auth.registered')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('rejects privilege fields and weak passwords at registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'X',
        'email' => 'x@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
        'platform_role' => 'SUPER_ADMIN',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['password', 'platform_role']]]);

    expect(User::query()->count())->toBe(0);
});

it('rejects duplicate emails case-insensitively', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dup', 'email' => 'DUP@example.com',
        'password' => 'Secret12345', 'password_confirmation' => 'Secret12345',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('logs in and out', function () {
    $user = User::factory()->create(['email' => 'login@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'login@example.com', 'password' => 'Password12345'])
        ->assertOk()
        ->assertJson(['two_factor' => false]);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    expect($user->fresh()->last_login_at)->not->toBeNull();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('returns a generic error for wrong credentials', function () {
    User::factory()->create(['email' => 'u@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'u@example.com', 'password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.email.0', 'Email hoặc mật khẩu không đúng.');

    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.email.0', 'Email hoặc mật khẩu không đúng.');
});

it('rate limits login attempts (5 per minute per email+IP)', function () {
    User::factory()->create(['email' => 'rl@example.com']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'bad-password'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'bad-password'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('blocks suspended users only after the password is proven', function () {
    User::factory()->suspended()->create(['email' => 's@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 's@example.com', 'password' => 'wrong-password'])
        ->assertStatus(422);

    $this->postJson('/api/v1/auth/login', ['email' => 's@example.com', 'password' => 'Password12345'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended');
});

it('logs out a user that gets suspended while signed in', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    $user->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($user->fresh())->getJson('/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended');
});

it('requires the 2FA challenge when two-factor is confirmed', function () {
    Event::fake([TwoFactorAuthenticationChallenged::class]);
    User::factory()->create([
        'email' => 'tfa@example.com',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/login', ['email' => 'tfa@example.com', 'password' => 'Password12345'])
        ->assertOk()
        ->assertJson(['two_factor' => true]);

    $this->getJson('/api/v1/me')->assertUnauthorized();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertNoContent();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_enabled', true);
});

it('updates the profile but never email, status or role', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/me', ['name' => 'Tên mới', 'locale' => 'en', 'timezone' => 'UTC'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Tên mới')
        ->assertJsonPath('data.locale', 'en');

    $this->actingAs($user)->patchJson('/api/v1/me', ['platform_role' => 'SUPER_ADMIN', 'email' => 'h@x.com'])
        ->assertStatus(422);

    expect($user->fresh()->platform_role)->toBeNull();
});
