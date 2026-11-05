<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Actions\CreateWorkspace;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * Create a workspace (with its OWNER membership) through the real action.
 */
function createWorkspace(?User $owner = null, string $name = 'Workspace'): Workspace
{
    $owner ??= User::factory()->create();

    return app(CreateWorkspace::class)->handle($owner, ['name' => $name]);
}

function addMember(Workspace $workspace, WorkspaceRole $role, ?User $user = null): WorkspaceMember
{
    $user ??= User::factory()->create();

    return app(TenantContext::class)->runAs($workspace, null, function () use ($user, $role): WorkspaceMember {
        $member = new WorkspaceMember;
        $member->forceFill([
            'user_id' => $user->id,
            'role' => $role,
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ])->save();

        return $member->load('user');
    });
}

/**
 * @return array{workspace: Workspace, owner: User, admin: User, editor: User, viewer: User}
 */
function workspaceWithRoles(string $name = 'Tenant'): array
{
    $owner = User::factory()->create();
    $workspace = createWorkspace($owner, $name);

    return [
        'workspace' => $workspace,
        'owner' => $owner,
        'admin' => addMember($workspace, WorkspaceRole::Admin)->user,
        'editor' => addMember($workspace, WorkspaceRole::Editor)->user,
        'viewer' => addMember($workspace, WorkspaceRole::Viewer)->user,
    ];
}

function memberOf(Workspace $workspace, User $user): WorkspaceMember
{
    return app(TenantContext::class)->bypass(fn () => WorkspaceMember::query()
        ->where('workspace_id', $workspace->id)->where('user_id', $user->id)->firstOrFail());
}
