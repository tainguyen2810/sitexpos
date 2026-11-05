<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Policies;

use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * SEO permissions: members read; OWNER / ADMIN / EDITOR (content.edit) edit
 * metadata, run audits and decide links; website SEO policy (schema
 * injection, canonical / noindex overrides) needs sites.manage.
 */
final class SeoPolicy
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

    public function configure(User $user): bool
    {
        return $this->context->can(Permission::SitesManage);
    }

    private function owns(Model $model): bool
    {
        return $this->context->has() && $model->getAttribute('workspace_id') === $this->context->workspaceId();
    }
}
