<?php

use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

it('lists members for every role', function () {
    ['workspace' => $ws, 'viewer' => $viewer] = workspaceWithRoles();

    $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->id}/members")
        ->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.role', 'OWNER');
});

it('OWNER can promote to ADMIN; ADMIN cannot', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();
    $viewerMember = memberOf($ws, $viewer);

    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$viewerMember->id}", ['role' => 'ADMIN'])->assertForbidden();
    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$viewerMember->id}", ['role' => 'EDITOR'])->assertOk()
        ->assertJsonPath('data.role', 'EDITOR');
    $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$viewerMember->id}", ['role' => 'ADMIN'])->assertOk();

    expect(AuditLog::query()->where('action', 'workspace.member.role_changed')->count())->toBe(2);
});

it('nobody can assign OWNER or change the OWNER via role update', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin, 'editor' => $editor] = workspaceWithRoles();

    $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $editor)->id, ['role' => 'OWNER'])
        ->assertStatus(422);
    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $owner)->id, ['role' => 'VIEWER'])
        ->assertForbidden();
});

it('ADMIN cannot modify or remove another ADMIN, and nobody changes their own role', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin] = workspaceWithRoles();
    $admin2 = addMember($ws, WorkspaceRole::Admin);

    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$admin2->id}", ['role' => 'VIEWER'])->assertForbidden();
    $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$admin2->id}")->assertForbidden();
    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $admin)->id, ['role' => 'EDITOR'])->assertForbidden();
});

it('EDITOR and VIEWER cannot manage members', function () {
    ['workspace' => $ws, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();

    $this->actingAs($editor)->patchJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $viewer)->id, ['role' => 'EDITOR'])->assertForbidden();
    $this->actingAs($viewer)->deleteJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $editor)->id)->assertForbidden();
});

it('members can leave; the OWNER cannot', function () {
    ['workspace' => $ws, 'owner' => $owner, 'viewer' => $viewer] = workspaceWithRoles();

    $this->actingAs($viewer)->deleteJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $viewer)->id)->assertNoContent();
    $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->id}")->assertNotFound();

    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $owner)->id)->assertForbidden();
});

it('removed members immediately lose access', function () {
    ['workspace' => $ws, 'admin' => $admin, 'editor' => $editor] = workspaceWithRoles();

    $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$ws->id}/members/".memberOf($ws, $editor)->id)->assertNoContent();
    $this->actingAs($editor)->getJson("/api/v1/workspaces/{$ws->id}/members")->assertNotFound();
});
