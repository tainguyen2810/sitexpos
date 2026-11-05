<?php

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
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
    ])
    ->toUseTrait(BelongsToWorkspace::class);

arch('kernel does not depend on module controllers')
    ->expect('SiteXPost\Kernel')
    ->not->toUse('SiteXPost\Modules\Workspace\Http');

arch('tenant API requests extend the base ApiRequest')
    ->expect('SiteXPost\Modules\Workspace\Http\Requests')
    ->toExtend(ApiRequest::class);

arch('strict types everywhere in app')
    ->expect('SiteXPost')
    ->toUseStrictTypes();
