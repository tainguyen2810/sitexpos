<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Policies;

use Illuminate\Auth\Access\Response;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Enums\Permission;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class WorkspaceInvitationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can(Permission::MembersManage);
    }

    public function create(User $user, WorkspaceRole $role): Response
    {
        $actor = $this->context->role();

        return $this->context->can(Permission::MembersManage) && $actor !== null && $actor->canManage($role)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, WorkspaceInvitation $invitation): Response
    {
        $actor = $this->context->role();

        return $this->context->has()
            && $invitation->workspace_id === $this->context->workspaceId()
            && $this->context->can(Permission::MembersManage)
            && $actor !== null
            && $actor->canManage($invitation->role)
            ? Response::allow()
            : Response::deny();
    }
}
