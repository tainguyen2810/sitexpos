<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Sites\Actions\SyncSite;
use SiteXPost\Modules\Sites\Domain\Site;

final class SyncSiteContent extends ConnectorJob
{
    protected function run(Site $site, ?JobRun $jobRun): void
    {
        app(SyncSite::class)->handle($site, static function (int $progress, string $step) use ($jobRun): void {
            $jobRun?->forceFill(['progress' => $progress, 'step' => mb_substr($step, 0, 64)])->save();
        });
    }
}
