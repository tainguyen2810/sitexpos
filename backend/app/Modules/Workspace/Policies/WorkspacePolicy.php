<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Policies;

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Tenant policies read the role from the server-side TenantContext, which is
 * only populated after membership verification (ResolveWorkspace).
 */
final class WorkspacePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::WorkspaceView);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::WorkspaceUpdate);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::WorkspaceDelete);
    }

    public function transferOwnership(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::WorkspaceTransfer);
    }

    public function viewMembers(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::MembersView);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::MembersManage);
    }

    public function viewAudit(User $user, Workspace $workspace): bool
    {
        return $this->allows($workspace, Permission::AuditView);
    }

    private function allows(Workspace $workspace, Permission $permission): bool
    {
        return $this->context->has()
            && $this->context->workspaceId() === $workspace->id
            && $this->context->can($permission);
    }
}
