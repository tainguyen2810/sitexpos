<?php

use Illuminate\Support\Facades\Notification;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Notifications\WorkspaceInvitationNotification;

function captureInvitationToken(): string
{
    $token = null;
    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($n, $channels, $notifiable) use (&$token) {
        $url = $n->toMail($notifiable)->actionUrl;
        $token = substr($url, strrpos($url, '/') + 1);

        return true;
    });

    return (string) $token;
}

it('invites by email, never exposes the token, and the invitee accepts', function () {
    Notification::fake();
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles('Hoa Mai');

    $res = $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'New@Example.com', 'role' => 'EDITOR'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.status', 'pending');
    expect($res->json('data'))->not->toHaveKeys(['token', 'token_hash']);

    $token = captureInvitationToken();
    expect(strlen($token))->toBe(64);

    $this->getJson("/api/v1/invitations/{$token}")->assertOk()
        ->assertJsonPath('data.workspace_name', 'Hoa Mai')
        ->assertJsonPath('data.role', 'EDITOR');

    $invitee = User::factory()->create(['email' => 'new@example.com']);
    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertOk()
        ->assertJsonPath('data.id', $ws->id);

    $this->actingAs($invitee)->getJson("/api/v1/workspaces/{$ws->id}")->assertOk()->assertJsonPath('data.my_role', 'EDITOR');

    // Token is single-use.
    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(409);
});

it('rejects acceptance by a different email', function () {
    Notification::fake();
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'right@example.com', 'role' => 'VIEWER']);
    $token = captureInvitationToken();

    $intruder = User::factory()->create(['email' => 'wrong@example.com']);
    $this->actingAs($intruder)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertForbidden()->assertJsonPath('error.code', 'invitation_email_mismatch');
    $this->actingAs($intruder)->getJson("/api/v1/workspaces/{$ws->id}")->assertNotFound();
});

it('rejects expired and revoked invitations', function () {
    Notification::fake();
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $res = $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'e@example.com', 'role' => 'VIEWER']);
    $token = captureInvitationToken();
    $invitee = User::factory()->create(['email' => 'e@example.com']);

    $this->travel(8)->days();
    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(409)
        ->assertJsonPath('error.code', 'invitation_expired');
    $this->travelBack();

    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->id}/invitations/".$res->json('data.id'))->assertNoContent();
    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(409)
        ->assertJsonPath('error.code', 'invitation_revoked');
});

it('ADMIN can invite EDITOR/VIEWER but not ADMIN; EDITOR cannot invite', function () {
    Notification::fake();
    ['workspace' => $ws, 'admin' => $admin, 'editor' => $editor] = workspaceWithRoles();

    $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'a1@example.com', 'role' => 'ADMIN'])->assertForbidden();
    $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'a2@example.com', 'role' => 'VIEWER'])->assertCreated();
    $this->actingAs($editor)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'a3@example.com', 'role' => 'VIEWER'])->assertForbidden();
    $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'a4@example.com', 'role' => 'OWNER'])->assertStatus(422);
});

it('refuses to invite an existing member and supersedes older invitations', function () {
    Notification::fake();
    ['workspace' => $ws, 'owner' => $owner, 'editor' => $editor] = workspaceWithRoles();

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => $editor->email, 'role' => 'VIEWER'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_member');

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'twice@example.com', 'role' => 'VIEWER'])->assertCreated();
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'twice@example.com', 'role' => 'EDITOR'])->assertCreated();

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/invitations")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'EDITOR');
});

it('stores only a hash of the invitation token', function () {
    Notification::fake();
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'h@example.com', 'role' => 'VIEWER']);
    $token = captureInvitationToken();

    $stored = app(TenantContext::class)->bypass(fn () => WorkspaceInvitation::query()->firstOrFail());
    expect($stored->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
});

it('returns 404 for unknown invitation tokens', function () {
    $this->getJson('/api/v1/invitations/'.str_repeat('a', 64))->assertNotFound()->assertJsonPath('error.code', 'not_found');
});
