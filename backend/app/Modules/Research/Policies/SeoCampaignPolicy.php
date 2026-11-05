<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Policies;

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Everyone in the workspace reads research data; OWNER/ADMIN/EDITOR
 * (content.edit) create campaigns, run research, approve ideas and plan the
 * calendar; OWNER/ADMIN (sites.manage) delete campaigns and connect Google
 * Search Console. Child records are reached only through their campaign
 * (scoped route bindings), so the campaign policy covers them.
 */
final class SeoCampaignPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can(Permission::WorkspaceView);
    }

    public function view(User $user, SeoCampaign $campaign): bool
    {
        return $this->owns($campaign) && $this->context->can(Permission::WorkspaceView);
    }

    public function create(User $user): bool
    {
        return $this->context->can(Permission::ContentEdit);
    }

    public function operate(User $user, SeoCampaign $campaign): bool
    {
        return $this->owns($campaign) && $this->context->can(Permission::ContentEdit);
    }

    public function manage(User $user, SeoCampaign $campaign): bool
    {
        return $this->owns($campaign) && $this->context->can(Permission::SitesManage);
    }

    private function owns(SeoCampaign $campaign): bool
    {
        return $this->context->has() && $campaign->workspace_id === $this->context->workspaceId();
    }
}
