<?php

use SiteXPost\Kernel\Tenancy\Middleware\ResolveWorkspace;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Platform\Domain\ImpersonationSession;

// PHASE1-AUDIT C14: an open support session is re-validated on every request.

function startSupportSession(object $test, User $staff, string $workspaceId): string
{
    return $test->actingAs($staff)->postJson('/api/admin/v1/impersonations', ['workspace_id' => $workspaceId, 'reason' => 'Ticket 42'])
        ->assertCreated()->json('data.id');
}

it('stops working once the staff member loses the impersonate permission', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $staff = User::factory()->platform(PlatformRole::Support, true)->create();
    $id = startSupportSession($this, $staff, $ws->id);

    $staff->forceFill(['platform_role' => PlatformRole::BillingAdmin])->save();

    $this->actingAs($staff->fresh())->withSession([ResolveWorkspace::IMPERSONATION_SESSION_KEY => $id])
        ->getJson("/api/v1/workspaces/{$ws->id}/members")->assertNotFound();
    $this->getJson('/api/v1/me')->assertJsonPath('data.impersonation', null);
});

it('stops working once the staff member no longer has confirmed 2FA', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $staff = User::factory()->platform(PlatformRole::Support, true)->create();
    $id = startSupportSession($this, $staff, $ws->id);

    $staff->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($staff->fresh())->withSession([ResolveWorkspace::IMPERSONATION_SESSION_KEY => $id])
        ->getJson("/api/v1/workspaces/{$ws->id}")->assertNotFound();
});

it('ends open support sessions when a SUPER_ADMIN changes the staff role', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $staff = User::factory()->platform(PlatformRole::Support, true)->create();
    $id = startSupportSession($this, $staff, $ws->id);
    $super = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();

    $this->actingAs($super)->patchJson("/api/admin/v1/staff/{$staff->id}", ['platform_role' => 'BILLING_ADMIN'])->assertOk();

    expect(ImpersonationSession::query()->find($id)->ended_at)->not->toBeNull();
});

it('keeps working for staff that still has the permission (control)', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $staff = User::factory()->platform(PlatformRole::Support, true)->create();
    $id = startSupportSession($this, $staff, $ws->id);

    $this->actingAs($staff)->withSession([ResolveWorkspace::IMPERSONATION_SESSION_KEY => $id])
        ->getJson("/api/v1/workspaces/{$ws->id}/members")->assertOk();
});
