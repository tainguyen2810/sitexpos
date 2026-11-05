<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\SessionManager;

it('verifies the email through the signed link used by the SPA', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->getJson($url)->assertNoContent();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects a tampered verification link', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->getJson($url.'x')->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('builds email links that point to the SPA', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function ($n) use ($user) {
        $mail = $n->toMail($user);

        return str_starts_with($mail->actionUrl, 'http://localhost:5173/verify-email?link=');
    });
});

it('does not reveal whether an email exists on forgot-password', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'exists@example.com']);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'exists@example.com'])->assertOk()->json();
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertOk()->json();

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
        return str_starts_with($n->toMail($user)->actionUrl, 'http://localhost:5173/reset-password?token=');
    });
});

it('resets the password and revokes all sessions', function () {
    config(['session.driver' => 'database']);
    Notification::fake();
    $user = User::factory()->create(['email' => 'reset@example.com']);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com']);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => 'reset@example.com',
        'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
    ])->assertOk();

    expect(Hash::check('BrandNew12345', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('changes the password only with the current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
        'current_password' => 'wrong', 'password' => 'Another12345', 'password_confirmation' => 'Another12345',
    ])->assertStatus(422);

    $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
        'current_password' => 'Password12345', 'password' => 'Another12345', 'password_confirmation' => 'Another12345',
    ])->assertOk();

    expect(Hash::check('Another12345', $user->fresh()->password))->toBeTrue();
});

it('lists and revokes sessions without exposing raw session ids', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'sess-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'ip_address' => '1.1.1.1', 'user_agent' => 'Firefox'],
        ['id' => 'sess-2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time() - 60, 'ip_address' => '2.2.2.2', 'user_agent' => 'Chrome'],
        ['id' => 'sess-x', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time(), 'ip_address' => '3.3.3.3', 'user_agent' => 'Edge'],
    ]);

    $list = $this->actingAs($user)->getJson('/api/v1/me/sessions')->assertOk()->json('data');
    $handles = collect($list)->pluck('id');
    expect($handles)->toContain(SessionManager::handle('sess-1'))
        ->and($handles)->not->toContain('sess-1')
        ->and($handles)->not->toContain(SessionManager::handle('sess-x'));

    // Another user's session handle cannot be revoked.
    $foreign = SessionManager::handle('sess-x');
    $this->actingAs($user)->deleteJson("/api/v1/me/sessions/{$foreign}")->assertNotFound();

    $this->actingAs($user)->deleteJson('/api/v1/me/sessions/'.SessionManager::handle('sess-2'))->assertNoContent();
    expect(DB::table('sessions')->where('id', 'sess-2')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'sess-1')->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'sess-x')->exists())->toBeTrue();
});
