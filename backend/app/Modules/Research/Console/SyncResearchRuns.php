<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Actions\ResearchRunSync;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Reconcile active research runs with their workflow (a run whose workflow
 * failed or was canceled outside the UI gets its status and quota settled).
 */
final class SyncResearchRuns extends Command
{
    protected $signature = 'sitex:research:sync';

    protected $description = 'Reconcile active research runs with their workflow state';

    public function handle(TenantContext $context, ResearchRunSync $sync): int
    {
        // Reviewed scope bypass: system reconciler, read-only selection of ids.
        $runs = $context->bypass(fn () => ResearchRun::query()->whereIn('status', ResearchRun::ACTIVE)
            ->where('updated_at', '<', now()->subMinutes(2))->limit(500)->get(['id', 'workspace_id']));
        $n = 0;
        foreach ($runs->groupBy('workspace_id') as $workspaceId => $group) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($group, $sync, &$n): void {
                foreach ($group as $row) {
                    $run = ResearchRun::query()->find($row->id);
                    if ($run !== null) {
                        $sync->sync($run);
                        $n++;
                    }
                }
            });
        }
        $this->info("Checked {$n} active research runs.");

        return self::SUCCESS;
    }
}
