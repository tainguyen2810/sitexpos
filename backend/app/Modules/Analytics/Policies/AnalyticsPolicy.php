<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Policies;

use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Analytics permissions (docs/SECURITY.md §Phase 8):
 *  - every member reads dashboards, opportunities and reports;
 *  - content.edit reviews opportunities / recommendations / decay, starts
 *    a content refresh, runs reports and the Copilot;
 *  - content.publish approves refreshed content and its WordPress update
 *    (same rule as the Writer approve gate);
 *  - sites.manage connects Search Console, picks the property, starts a
 *    sync and edits thresholds.
 */
final class AnalyticsPolicy
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

    public function create(User $user): bool
    {
        return $this->context->can(Permission::ContentEdit);
    }

    public function operate(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->context->can(Permission::ContentEdit);
    }

    public function approve(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->context->can(Permission::ContentPublish);
    }

    public function configure(User $user): bool
    {
        return $this->context->can(Permission::SitesManage);
    }

    private function owns(Model $model): bool
    {
        return $this->context->has() && $model->getAttribute('workspace_id') === $this->context->workspaceId();
    }
}
