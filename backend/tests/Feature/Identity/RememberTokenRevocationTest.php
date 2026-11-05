<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

// PHASE1-AUDIT A6: deleting session rows must also kill "remember me" cookies.

function recallerRequest(object $test, User $user, string $token): TestResponse
{
    app('auth')->forgetGuards();

    return $test->withCredentials()->withHeader('Referer', 'http://localhost/')
        ->withCookie(Auth::guard('web')->getRecallerName(), $user->id.'|'.$token.'|'.$user->fresh()->password)
        ->getJson('/api/v1/me');
}

it('accepts a valid remember-me cookie (control)', function () {
    $user = User::factory()->create();
    $user->forceFill(['remember_token' => 'recaller-token-abc'])->save();

    recallerRequest($this, $user, 'recaller-token-abc')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('logging out other devices invalidates their remember-me cookies', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'Secret-pass-123']);
    $user->forceFill(['remember_token' => 'recaller-token-abc'])->save();

    $this->actingAs($user)->deleteJson('/api/v1/me/sessions/others', ['password' => 'Secret-pass-123'])->assertOk();

    expect($user->fresh()->remember_token)->not->toBe('recaller-token-abc');
    recallerRequest($this, $user, 'recaller-token-abc')->assertUnauthorized();
});

it('rotates the remember token when the password is changed', function () {
    $user = User::factory()->create(['password' => 'Secret-pass-123']);
    $user->forceFill(['remember_token' => 'recaller-token-abc'])->save();

    $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
        'current_password' => 'Secret-pass-123', 'password' => 'Another-pass-456', 'password_confirmation' => 'Another-pass-456',
    ])->assertOk();

    expect($user->fresh()->remember_token)->not->toBe('recaller-token-abc');
});

it('rotates the remember token when an admin suspends the user', function () {
    $user = User::factory()->create();
    $user->forceFill(['remember_token' => 'recaller-token-abc'])->save();
    $support = User::factory()->platform(PlatformRole::Support, true)->create();

    $this->actingAs($support)->postJson("/api/admin/v1/users/{$user->id}/suspend", ['reason' => 'spam account'])->assertOk();

    expect($user->fresh()->remember_token)->not->toBe('recaller-token-abc');
});
