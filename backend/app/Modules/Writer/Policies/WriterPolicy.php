<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Policies;

use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * AI Writer permissions (articles, presets, batches):
 *  - everyone in the workspace reads (workspace.view);
 *  - OWNER / ADMIN / EDITOR (content.edit) create, run AI, edit, verify
 *    claims of LOW–HIGH risk and request changes;
 *  - OWNER / ADMIN (content.publish) approve articles and verify CRITICAL
 *    claims manually.
 * Child rows are reached only through their article (scoped bindings).
 */
final class WriterPolicy
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

    private function owns(Model $model): bool
    {
        return $this->context->has() && $model->getAttribute('workspace_id') === $this->context->workspaceId();
    }
}
