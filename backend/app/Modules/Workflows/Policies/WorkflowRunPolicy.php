<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Policies;

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Everyone in the workspace sees its background jobs; OWNER/ADMIN/EDITOR
 * (content.edit) start, cancel and retry them.
 */
final class WorkflowRunPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can(Permission::WorkspaceView);
    }

    public function view(User $user, WorkflowRun $run): bool
    {
        return $this->owns($run) && $this->context->can(Permission::WorkspaceView);
    }

    public function create(User $user): bool
    {
        return $this->context->can(Permission::ContentEdit);
    }

    public function operate(User $user, WorkflowRun $run): bool
    {
        return $this->owns($run) && $this->context->can(Permission::ContentEdit);
    }

    private function owns(WorkflowRun $run): bool
    {
        return $this->context->has() && $run->workspace_id === $this->context->workspaceId();
    }
}
