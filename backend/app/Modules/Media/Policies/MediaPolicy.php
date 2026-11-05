<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Policies;

use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Media permissions: every member reads (workspace.view); OWNER / ADMIN /
 * EDITOR (content.edit) upload, generate, edit and place images.
 */
final class MediaPolicy
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

    private function owns(Model $model): bool
    {
        return $this->context->has() && $model->getAttribute('workspace_id') === $this->context->workspaceId();
    }
}
