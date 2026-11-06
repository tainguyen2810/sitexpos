<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Policies;

use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * SEO Agent permissions: members read runs and logs; content.edit can stop
 * the agent (emergency stop), start a manual run, approve / reject / retry
 * tasks (the approver must also hold the tool's own permission, checked by
 * the orchestrator); sites.manage configures autonomy, limits and
 * schedules. Nobody can give the agent rights they do not have.
 */
final class AgentAccessPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can(Permission::WorkspaceView);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->context->can(Permission::WorkspaceView);
    }

    public function operate(User $user, ?Model $model = null): bool
    {
        return ($model === null || $this->owns($model)) && $this->context->can(Permission::ContentEdit);
    }

    public function configure(User $user, ?Model $model = null): bool
    {
        return ($model === null || $this->owns($model)) && $this->context->can(Permission::SitesManage);
    }

    private function owns(Model $model): bool
    {
        return $this->context->has() && $model->getAttribute('workspace_id') === $this->context->workspaceId();
    }
}
