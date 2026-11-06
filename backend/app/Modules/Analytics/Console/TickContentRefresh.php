<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Jobs\AdvanceRefreshJob;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Moves refresh jobs that wait for the Writer (AI proposals, fact-check)
 * or the Publisher forward, and closes "monitoring" jobs once their
 * before/after experiment has finished. Never approves anything.
 */
final class TickContentRefresh extends Command
{
    protected $signature = 'sitex:analytics:refresh-tick';

    protected $description = 'Advance content refresh jobs waiting for AI, fact-check or publishing';

    public function handle(TenantContext $context): int
    {
        $jobs = $context->bypass(fn () => ContentRefreshJob::query()->whereIn('status', ['queued', 'drafting', 'fact_checking', 'publishing', 'monitoring'])
            ->where('updated_at', '<', now()->subMinute())->limit(200)->get(['id', 'workspace_id', 'status', 'experiment_id']));
        $n = 0;
        foreach ($jobs->groupBy('workspace_id') as $workspaceId => $group) {
            $ws = Workspace::query()->find($workspaceId);
            if ($ws === null) {
                continue;
            }
            $context->runAs($ws, null, function () use ($group, &$n): void {
                foreach ($group as $j) {
                    if ($j->status === 'monitoring') {
                        $done = $j->experiment_id === null || SeoExperiment::query()->whereKey($j->experiment_id)->whereIn('status', ['completed', 'insufficient_data', 'cancelled'])->exists();
                        if ($done) {
                            ContentRefreshJob::query()->whereKey($j->id)->update(['status' => 'completed']);
                        }

                        continue;
                    }
                    AdvanceRefreshJob::dispatch($j->id);
                    $n++;
                }
            });
        }
        $this->info("Advanced {$n} refresh jobs");

        return self::SUCCESS;
    }
}
