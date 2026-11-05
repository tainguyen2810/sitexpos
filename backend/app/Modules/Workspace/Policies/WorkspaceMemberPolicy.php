<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Policies;

use Illuminate\Auth\Access\Response;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\Permission;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class WorkspaceMemberPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function update(User $user, WorkspaceMember $member, WorkspaceRole $newRole): Response
    {
        if (! $this->inContext($member) || ! $this->context->can(Permission::MembersManage)) {
            return Response::deny();
        }

        if ($member->user_id === $user->id) {
            return Response::deny(__('api.cannot_change_own_role'));
        }

        $actor = $this->context->role();

        return $actor !== null && $actor->canManage($member->role) && $actor->canManage($newRole)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, WorkspaceMember $member): Response
    {
        if (! $this->inContext($member)) {
            return Response::deny();
        }

        // Leaving a workspace: any non-owner member may remove themselves (not while impersonating).
        if ($member->user_id === $user->id && ! $this->context->isImpersonating()) {
            return $member->role !== WorkspaceRole::Owner ? Response::allow() : Response::deny(__('api.owner_cannot_leave'));
        }

        $actor = $this->context->role();

        return $this->context->can(Permission::MembersManage) && $actor !== null && $actor->canManage($member->role)
            ? Response::allow()
            : Response::deny();
    }

    private function inContext(WorkspaceMember $member): bool
    {
        return $this->context->has() && $member->workspace_id === $this->context->workspaceId();
    }
}
