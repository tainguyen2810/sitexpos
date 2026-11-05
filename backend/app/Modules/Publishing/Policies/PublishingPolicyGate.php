<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Policies;

use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Publishing permissions:
 * - read: every member (workspace.view);
 * - prepare (create job, re-check gates, cancel, preview): content.edit;
 * - publish (approve, retry, bulk, resolve conflicts incl. overwrite):
 *   content.publish (OWNER / ADMIN);
 * - website publishing policy (incl. auto-publish): sites.manage.
 */
final class PublishingPolicyGate
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

    public function publish(User $user, ?Model $model = null): bool
    {
        return ($model === null || $this->owns($model)) && $this->context->can(Permission::ContentPublish);
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
