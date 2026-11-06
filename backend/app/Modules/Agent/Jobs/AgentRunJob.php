<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Engine\AgentOrchestrator;

final class AgentRunJob extends TenantJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public string $runId)
    {
        parent::__construct();
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return 'agent-run:'.$this->runId;
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $run = AgentRun::query()->find($this->runId);
        if ($run !== null) {
            $orchestrator->process($run);
        }
    }
}
