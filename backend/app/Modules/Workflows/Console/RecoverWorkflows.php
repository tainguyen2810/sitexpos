<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Scheduled every minute: re-dispatches runs that lost their worker (lease
 * expired after a crash/restart) or their queue job (Redis restart, deploy).
 */
final class RecoverWorkflows extends Command
{
    protected $signature = 'sitex:workflows:recover';

    protected $description = 'Re-dispatch workflow runs whose worker died or whose queue job was lost';

    public function handle(WorkflowEngine $engine, TenantContext $context): int
    {
        // System sweep across tenants: reviewed scope bypass (ids only).
        $candidates = $context->bypass(fn () => WorkflowRun::query()
            ->whereIn('status', [WorkflowStatus::Pending->value, WorkflowStatus::Running->value, WorkflowStatus::RetryScheduled->value])
            ->where('updated_at', '<', now()->subSeconds(30))
            ->orderBy('updated_at')->limit(500)
            ->get(['id', 'workspace_id']));

        $recovered = 0;
        foreach ($candidates->groupBy('workspace_id') as $workspaceId => $runs) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($runs, $engine, &$recovered): void {
                foreach ($runs as $row) {
                    $run = WorkflowRun::query()->find($row->id);
                    if ($run !== null && $engine->recoverable($run)) {
                        $engine->recover($run);
                        $recovered++;
                    }
                }
            });
        }

        $this->info("Recovered {$recovered} workflow run(s).");

        return self::SUCCESS;
    }
}
