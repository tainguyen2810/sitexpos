<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Jobs;

use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Analytics\Actions\Copilot;
use SiteXPost\Modules\Analytics\Domain\CopilotQuery;

final class CopilotPhraseJob extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $queryId)
    {
        parent::__construct();
        $this->onQueue('analytics');
    }

    public function handle(Copilot $copilot): void
    {
        $row = CopilotQuery::query()->find($this->queryId);
        if ($row !== null) {
            $copilot->phrase($row);
        }
    }
}
