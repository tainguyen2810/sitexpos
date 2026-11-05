<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Media\Actions\ImportRemoteMedia;
use SiteXPost\Modules\Sites\Connector\ConnectorException;

final class SyncRemoteMedia extends MediaJob
{
    public function __construct(public string $siteId, ?string $jobRunId = null)
    {
        parent::__construct($jobRunId);
    }

    protected function run(?JobRun $run): void
    {
        try {
            app(ImportRemoteMedia::class)->handle($this->siteId);
        } catch (ConnectorException $e) {
            self::failRun($run, $e->errorCode, $e->getMessage());
        }
    }
}
