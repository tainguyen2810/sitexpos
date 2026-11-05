<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace;

use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Policies\WorkspaceInvitationPolicy;
use SiteXPost\Modules\Workspace\Policies\WorkspaceMemberPolicy;
use SiteXPost\Modules\Workspace\Policies\WorkspacePolicy;

final class WorkspaceServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        Workspace::class => WorkspacePolicy::class,
        WorkspaceMember::class => WorkspaceMemberPolicy::class,
        WorkspaceInvitation::class => WorkspaceInvitationPolicy::class,
    ];
}
