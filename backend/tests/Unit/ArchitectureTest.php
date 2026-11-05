<?php

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteActivityLog;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Domain\SiteHealthCheck;
use SiteXPost\Modules\Sites\Domain\SiteMedia;
use SiteXPost\Modules\Sites\Domain\SitePairing;
use SiteXPost\Modules\Sites\Domain\SitePublication;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('the default App namespace is not used')
    ->expect('App')
    ->not->toBeUsed();

arch('tenant-owned models use the fail-closed workspace scope')
    ->expect([
        WorkspaceMember::class,
        WorkspaceInvitation::class,
        JobRun::class,
        Site::class,
        SitePairing::class,
        SiteCredential::class,
        SiteHealthCheck::class,
        SiteTaxonomy::class,
        SiteContentItem::class,
        SitePublication::class,
        SiteMedia::class,
        SiteActivityLog::class,
    ])
    ->toUseTrait(BelongsToWorkspace::class);

arch('kernel does not depend on module controllers')
    ->expect('SiteXPost\Kernel')
    ->not->toUse('SiteXPost\Modules\Workspace\Http');

arch('tenant API requests extend the base ApiRequest')
    ->expect(['SiteXPost\Modules\Workspace\Http\Requests', 'SiteXPost\Modules\Sites\Http\Requests'])
    ->toExtend(ApiRequest::class);

arch('the WordPress connector never touches AI provider credentials')
    ->expect('SiteXPost\Modules\Sites')
    ->not->toUse(['SiteXPost\Modules\Ai', 'SiteXPost\Modules\Providers']);

arch('strict types everywhere in app')
    ->expect('SiteXPost')
    ->toUseStrictTypes();
