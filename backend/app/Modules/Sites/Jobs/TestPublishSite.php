<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Sites\Actions\TestPublish;
use SiteXPost\Modules\Sites\Domain\Site;

final class TestPublishSite extends ConnectorJob
{
    protected function run(Site $site, ?JobRun $jobRun): void
    {
        app(TestPublish::class)->handle($site);
    }
}
