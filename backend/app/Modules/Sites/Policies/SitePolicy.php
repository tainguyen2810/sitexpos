<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Policies;

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * SECURITY.md §3.2: everyone in the workspace sees sites; OWNER/ADMIN/EDITOR
 * run health checks and syncs (read-only on WordPress); only OWNER/ADMIN
 * connect, rotate keys, write test content, disconnect or delete.
 */
final class SitePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can(Permission::WorkspaceView);
    }

    public function view(User $user, Site $site): bool
    {
        return $this->owns($site) && $this->context->can(Permission::WorkspaceView);
    }

    public function create(User $user): bool
    {
        return $this->context->can(Permission::SitesManage);
    }

    public function operate(User $user, Site $site): bool
    {
        return $this->owns($site) && $this->context->can(Permission::ContentEdit);
    }

    public function manage(User $user, Site $site): bool
    {
        return $this->owns($site) && $this->context->can(Permission::SitesManage);
    }

    private function owns(Site $site): bool
    {
        return $this->context->has() && $site->workspace_id === $this->context->workspaceId();
    }
}
