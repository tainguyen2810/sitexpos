<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Jobs;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Analytics\Actions\ContentRefresh;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;

/**
 * Runs the automatic refresh steps (WordPress check, GSC analysis, AI
 * proposals) off the HTTP request. `approveBy` carries the human approver
 * captured by the controller after its permission check.
 */
final class AdvanceRefreshJob extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $refreshJobId, public ?string $approveBy = null, public ?string $note = null)
    {
        parent::__construct();
        $this->onQueue('analytics');
    }

    public function handle(ContentRefresh $refresh): void
    {
        $job = ContentRefreshJob::query()->find($this->refreshJobId);
        if ($job === null) {
            return;
        }
        try {
            if ($this->approveBy !== null) {
                $job = $refresh->approve($job, $this->approveBy, $this->note);
            }
            $refresh->advance($job);
        } catch (ApiException) {
            // State (conflict / failed) is already persisted on the job row.
        }
    }
}
