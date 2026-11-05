<?php

use SiteXPost\Kernel\KernelServiceProvider;
use SiteXPost\Modules\Audit\AuditServiceProvider;
use SiteXPost\Modules\Identity\IdentityServiceProvider;
use SiteXPost\Modules\Platform\PlatformServiceProvider;
use SiteXPost\Modules\Workspace\WorkspaceServiceProvider;

return [
    KernelServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    WorkspaceServiceProvider::class,
    PlatformServiceProvider::class,
];
