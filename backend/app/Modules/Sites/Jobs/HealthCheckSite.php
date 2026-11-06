<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Sites\Actions\RunHealthCheck;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Domain\Site;

final class HealthCheckSite extends ConnectorJob
{
    protected function run(Site $site, ?JobRun $jobRun): void
    {
        $check = app(RunHealthCheck::class)->handle($site);
        if (! $check->ok) {
            throw new ConnectorException((string) $check->error_code, (string) ($site->last_error_message ?? __('sites.errors.health_failed')));
        }
    }
}
