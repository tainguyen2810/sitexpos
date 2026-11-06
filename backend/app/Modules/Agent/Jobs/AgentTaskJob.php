<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Engine\AgentOrchestrator;

/**
 * One tool call. Unique per task + attempt; the orchestrator also claims the
 * row atomically, so a duplicated message never executes a tool twice.
 */
final class AgentTaskJob extends TenantJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public string $taskId)
    {
        parent::__construct();
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return 'agent-task:'.$this->taskId;
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $task = AgentTask::query()->find($this->taskId);
        if ($task !== null) {
            $orchestrator->execute($task);
        }
    }
}
