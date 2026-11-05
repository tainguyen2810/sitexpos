<?php

use SiteXPost\Kernel\Tenancy\Middleware\ResolveWorkspace;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Platform\Domain\ImpersonationSession;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

function staff(PlatformRole $role, bool $twoFactor = true): User
{
    return User::factory()->platform($role, $twoFactor)->create();
}

it('hides the admin API from customers (404)', function () {
    $customer = User::factory()->create();
    createWorkspace($customer);

    $this->actingAs($customer)->getJson('/api/admin/v1/dashboard')->assertNotFound();
    $this->actingAs($customer)->getJson('/api/admin/v1/workspaces')->assertNotFound();
});

it('requires confirmed 2FA for platform staff', function () {
    $this->actingAs(staff(PlatformRole::SuperAdmin, twoFactor: false))->getJson('/api/admin/v1/dashboard')
        ->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
});

it('shows real aggregate numbers on the dashboard', function () {
    createWorkspace(name: 'W1');
    createWorkspace(name: 'W2');
    $admin = staff(PlatformRole::SuperAdmin);

    $this->actingAs($admin)->getJson('/api/admin/v1/dashboard')->assertOk()
        ->assertJsonPath('data.workspaces.total', 2)
        ->assertJsonPath('data.users.total', 3);
});

it('enforces the platform permission matrix', function () {
    $ws = createWorkspace();
    $customer = User::factory()->create();
    $support = staff(PlatformRole::Support);
    $billing = staff(PlatformRole::BillingAdmin);

    $this->actingAs($support)->getJson('/api/admin/v1/staff')->assertForbidden();
    $this->actingAs($billing)->postJson("/api/admin/v1/workspaces/{$ws->id}/suspend", ['reason' => 'abuse report'])->assertForbidden();
    $this->actingAs($billing)->postJson('/api/admin/v1/impersonations', ['workspace_id' => $ws->id, 'reason' => 'ticket 1'])->assertForbidden();
    $this->actingAs($billing)->getJson('/api/admin/v1/workspaces')->assertOk();
    $this->actingAs($support)->postJson("/api/admin/v1/users/{$customer->id}/suspend", ['reason' => 'spam account'])->assertOk();
});

it('suspends and unsuspends a workspace with a reason and audit', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $support = staff(PlatformRole::Support);

    $this->actingAs($support)->postJson("/api/admin/v1/workspaces/{$ws->id}/suspend", [])->assertStatus(422);
    $this->actingAs($support)->postJson("/api/admin/v1/workspaces/{$ws->id}/suspend", ['reason' => 'Payment fraud'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}")->assertForbidden();

    $this->actingAs($support)->postJson("/api/admin/v1/workspaces/{$ws->id}/unsuspend", ['reason' => 'Resolved'])->assertOk();
    expect($ws->fresh()->status)->toBe(WorkspaceStatus::Active)
        ->and(AuditLog::query()->where('action', 'platform.workspace.suspended')->where('actor_id', $support->id)->exists())->toBeTrue();
});

it('suspending a user revokes access; staff cannot be suspended by SUPPORT', function () {
    $support = staff(PlatformRole::Support);
    $other = staff(PlatformRole::BillingAdmin);
    $customer = User::factory()->create();

    $this->actingAs($support)->postJson("/api/admin/v1/users/{$other->id}/suspend", ['reason' => 'no reason'])->assertForbidden();
    $this->actingAs($support)->postJson("/api/admin/v1/users/{$support->id}/suspend", ['reason' => 'self test'])->assertStatus(409);

    $this->actingAs($support)->postJson("/api/admin/v1/users/{$customer->id}/suspend", ['reason' => 'spam account'])->assertOk();
    $this->actingAs($customer->fresh())->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
});

it('manages staff roles and protects the last SUPER_ADMIN', function () {
    $super = staff(PlatformRole::SuperAdmin);
    $candidate = User::factory()->create(['email' => 'cand@example.com']);

    $this->actingAs($super)->postJson('/api/admin/v1/staff', ['email' => 'cand@example.com', 'platform_role' => 'SUPPORT'])
        ->assertCreated()->assertJsonPath('data.platform_role', 'SUPPORT');

    $this->actingAs($super)->patchJson("/api/admin/v1/staff/{$candidate->id}", ['platform_role' => null])
        ->assertOk()->assertJsonPath('data.platform_role', null);

    $this->actingAs($super)->patchJson("/api/admin/v1/staff/{$super->id}", ['platform_role' => null])
        ->assertStatus(409)->assertJsonPath('error.code', 'cannot_change_own_platform_role');
});

it('impersonation is read-only, audited and limited to the chosen workspace', function () {
    ['workspace' => $ws] = workspaceWithRoles('Target');
    $otherWs = createWorkspace(name: 'Other');
    $support = staff(PlatformRole::Support);

    $res = $this->actingAs($support)->postJson('/api/admin/v1/impersonations', ['workspace_id' => $ws->id, 'reason' => 'Ticket #123'])
        ->assertCreated()->assertJsonPath('data.read_only', true);
    $sessionId = $res->json('data.id');

    $this->actingAs($support)->withSession([ResolveWorkspace::IMPERSONATION_SESSION_KEY => $sessionId]);

    $this->getJson("/api/v1/workspaces/{$ws->id}")->assertOk()
        ->assertJsonPath('data.impersonating', true)
        ->assertJsonPath('data.permissions', ['workspace.view', 'members.view', 'audit.view']);
    $this->getJson("/api/v1/workspaces/{$ws->id}/members")->assertOk();

    $this->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'changed'])
        ->assertForbidden()->assertJsonPath('error.code', 'impersonation_read_only');
    $this->getJson("/api/v1/workspaces/{$otherWs->id}")->assertNotFound();

    expect($ws->fresh()->name)->toBe('Target')
        ->and(AuditLog::query()->where('action', 'platform.impersonation.started')->where('workspace_id', $ws->id)->exists())->toBeTrue();

    $this->getJson('/api/v1/me')->assertJsonPath('data.impersonation.workspace_id', $ws->id);
});

it('impersonation expires automatically', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $support = staff(PlatformRole::Support);
    $id = $this->actingAs($support)->postJson('/api/admin/v1/impersonations', ['workspace_id' => $ws->id, 'reason' => 'Ticket #9'])->json('data.id');

    $this->travel(31)->minutes();

    $this->actingAs($support)->withSession([ResolveWorkspace::IMPERSONATION_SESSION_KEY => $id])
        ->getJson("/api/v1/workspaces/{$ws->id}")->assertNotFound();
    expect(ImpersonationSession::query()->find($id)->isActive())->toBeFalse();
});

it('scopes admin audit logs by platform role', function () {
    Audit::record('billing.invoice.paid');
    Audit::record('platform.workspace.suspended');

    $support = collect($this->actingAs(staff(PlatformRole::Support))->getJson('/api/admin/v1/audit-logs')->json('data'))->pluck('action');
    $billing = collect($this->actingAs(staff(PlatformRole::BillingAdmin))->getJson('/api/admin/v1/audit-logs')->json('data'))->pluck('action');

    expect($support)->not->toContain('billing.invoice.paid')->toContain('platform.workspace.suspended')
        ->and($billing->all())->toBe(['billing.invoice.paid']);
});

it('promotes the first super admin only through the CLI', function () {
    User::factory()->create(['email' => 'boss@example.com']);
    User::factory()->unverified()->create(['email' => 'unverified@example.com']);

    $this->artisan('sitex:make-super-admin', ['email' => 'unverified@example.com'])->assertFailed();
    $this->artisan('sitex:make-super-admin', ['email' => 'boss@example.com'])->assertSuccessful();

    expect(User::query()->where('email', 'boss@example.com')->first()->platform_role)->toBe(PlatformRole::SuperAdmin);
});

it('exposes platform permissions on /me as a UI hint only', function () {
    $support = staff(PlatformRole::Support);
    $customer = User::factory()->create();

    $perms = $this->actingAs($support)->getJson('/api/v1/me')->assertOk()->json('data.platform_permissions');
    expect($perms)->toContain('platform.impersonate')->not->toContain('platform.staff.manage');

    $this->actingAs($customer)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.platform_permissions', []);
});
