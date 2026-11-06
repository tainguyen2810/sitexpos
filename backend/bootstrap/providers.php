<?php

use SiteXPost\Kernel\KernelServiceProvider;
use SiteXPost\Modules\AiGateway\AiGatewayServiceProvider;
use SiteXPost\Modules\Audit\AuditServiceProvider;
use SiteXPost\Modules\Billing\BillingServiceProvider;
use SiteXPost\Modules\Identity\IdentityServiceProvider;
use SiteXPost\Modules\Media\MediaServiceProvider;
use SiteXPost\Modules\Platform\PlatformServiceProvider;
use SiteXPost\Modules\Publishing\PublishingServiceProvider;
use SiteXPost\Modules\Research\ResearchServiceProvider;
use SiteXPost\Modules\Seo\SeoServiceProvider;
use SiteXPost\Modules\Sites\SitesServiceProvider;
use SiteXPost\Modules\Workflows\WorkflowsServiceProvider;
use SiteXPost\Modules\Workspace\WorkspaceServiceProvider;
use SiteXPost\Modules\Writer\WriterServiceProvider;

return [
    KernelServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    WorkspaceServiceProvider::class,
    PlatformServiceProvider::class,
    SitesServiceProvider::class,
    WorkflowsServiceProvider::class,
    AiGatewayServiceProvider::class,
    ResearchServiceProvider::class,
    WriterServiceProvider::class,
    MediaServiceProvider::class,
    SeoServiceProvider::class,
    PublishingServiceProvider::class,
    BillingServiceProvider::class,
];
