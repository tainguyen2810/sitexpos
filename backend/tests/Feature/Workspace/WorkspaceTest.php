<?php

use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

it('creates a workspace with the creator as OWNER', function () {
    $user = User::factory()->create();

    $res = $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'Shop Hoa Mai'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Shop Hoa Mai')
        ->assertJsonPath('data.my_role', 'OWNER')
        ->assertJsonPath('data.permissions', fn ($p) => in_array('workspace.delete', $p, true));

    $id = $res->json('data.id');
    $this->actingAs($user)->getJson('/api/v1/workspaces')->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.role', 'OWNER');

    $this->actingAs($user)->getJson("/api/v1/workspaces/{$id}")->assertOk()
        ->assertJsonPath('data.my_role', 'OWNER')
        ->assertJsonPath('data.permissions', fn ($p) => in_array('billing.manage', $p, true));

    expect(AuditLog::query()->where('action', 'workspace.created')->where('workspace_id', $id)->exists())->toBeTrue();
});

it('requires a verified email to create a workspace', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'X Shop'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'email_not_verified');
});

it('ignores nothing silently: workspace_id / owner_id in the body is rejected', function () {
    $user = User::factory()->create();
    $other = createWorkspace();

    $this->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'X Shop', 'workspace_id' => $other->id, 'owner_id' => $other->owner_id])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['workspace_id', 'owner_id']]]);
});

it('lets a user belong to several workspaces with different roles', function () {
    $user = User::factory()->create();
    $mine = createWorkspace($user, 'Mine');
    $other = createWorkspace(name: 'Other');
    addMember($other, WorkspaceRole::Editor, $user);

    $list = collect($this->actingAs($user)->getJson('/api/v1/workspaces')->assertOk()->json('data'));

    expect($list)->toHaveCount(2)
        ->and($list->firstWhere('id', $mine->id)['role'])->toBe('OWNER')
        ->and($list->firstWhere('id', $other->id)['role'])->toBe('EDITOR');
});

it('enforces update permissions per role', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();

    $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'By owner'])->assertOk();
    $this->actingAs($admin)->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'By admin'])->assertOk();
    $this->actingAs($editor)->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'By editor'])
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $this->actingAs($viewer)->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'By viewer'])->assertForbidden();

    expect($ws->fresh()->name)->toBe('By admin');
});

it('exposes role-specific permissions for the UI', function () {
    ['workspace' => $ws, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();

    $editorPerms = $this->actingAs($editor)->getJson("/api/v1/workspaces/{$ws->id}")->json('data.permissions');
    $viewerPerms = $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->id}")->json('data.permissions');

    expect($editorPerms)->toContain('content.edit')->not->toContain('members.manage')
        ->and($viewerPerms)->toBe(['workspace.view', 'members.view']);
});

it('only the OWNER can delete, with name confirmation and password', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin] = workspaceWithRoles('Delete Me');

    $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$ws->id}", ['confirm_name' => 'Delete Me', 'password' => 'Password12345'])
        ->assertForbidden();

    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->id}", ['confirm_name' => 'Wrong', 'password' => 'Password12345'])
        ->assertStatus(422);

    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->id}", ['confirm_name' => 'Delete Me', 'password' => 'Password12345'])
        ->assertNoContent();

    expect($ws->fresh()->status)->toBe(WorkspaceStatus::PendingDeletion);
    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}")->assertNotFound();
    $this->actingAs($owner)->getJson('/api/v1/workspaces')->assertJsonCount(0, 'data');
});

it('transfers ownership; previous owner becomes ADMIN', function () {
    ['workspace' => $ws, 'owner' => $owner, 'editor' => $editor] = workspaceWithRoles();
    $editorMember = memberOf($ws, $editor);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/transfer-ownership", [
        'member_id' => $editorMember->id, 'password' => 'Password12345',
    ])->assertNoContent();

    expect(memberOf($ws, $editor)->role->value)->toBe('OWNER')
        ->and(memberOf($ws, $owner)->role->value)->toBe('ADMIN')
        ->and(Workspace::query()->find($ws->id)->owner_id)->toBe($editor->id);
});

it('suspended workspaces are not accessible to members', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $ws->forceFill(['status' => WorkspaceStatus::Suspended])->save();

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}")
        ->assertForbidden()->assertJsonPath('error.code', 'workspace_suspended');
});

it('returns a real-data dashboard', function () {
    ['workspace' => $ws, 'viewer' => $viewer] = workspaceWithRoles();

    $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->id}/dashboard")->assertOk()
        ->assertJsonPath('data.members_count', 4)
        ->assertJsonPath('data.sites_count', 0)
        ->assertJsonPath('data.modules.billing.available', false);
});
