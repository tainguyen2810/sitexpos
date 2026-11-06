<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Jobs;

use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Publishing\Actions\ExecutePublish;

/**
 * Queue entry for one publish attempt (`publishing` queue). One try per
 * message: retries are scheduled by ExecutePublish with backoff and
 * re-dispatched by the recovery command if a message is lost.
 */
final class RunPublishingJob extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public string $publishingJobId)
    {
        parent::__construct();
        $this->onQueue('publishing');
    }

    public function handle(ExecutePublish $publisher): void
    {
        $publisher->run($this->publishingJobId);
    }
}
