<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Jobs;

use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Writer\Actions\LearnStyle;

/**
 * Queue body of "Learn writing style" (fetch + one paid AI call). One try:
 * a failure is shown on the job run and the user retries explicitly.
 */
final class LearnWritingStyle extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<string>  $itemIds
     * @param  list<array{title?: string|null, text: string}>  $samples
     */
    public function __construct(public string $presetId, public string $jobRunId, public ?string $siteId, public array $itemIds, public array $samples)
    {
        parent::__construct();
        $this->onQueue('writing');
    }

    public function handle(LearnStyle $action): void
    {
        $action->run($this->presetId, $this->jobRunId, $this->siteId, $this->itemIds, $this->samples);
    }
}
