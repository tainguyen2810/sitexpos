<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Actions\StartResearch;
use SiteXPost\Modules\Research\Domain\ResearchSchedule;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Queue scheduled research runs that are due. Each schedule slot has its own
 * idempotency key, so running this twice for the same slot never starts two
 * runs. Quota, concurrency and the "one active run per campaign" rule are
 * enforced by StartResearch exactly as for manual runs.
 */
final class DispatchResearchSchedules extends Command
{
    protected $signature = 'sitex:research:schedule';

    protected $description = 'Queue due scheduled SEO research runs';

    public function handle(TenantContext $context, StartResearch $start): int
    {
        // Reviewed scope bypass: system scheduler, read-only selection of ids.
        $due = $context->bypass(fn () => ResearchSchedule::query()->where('is_enabled', true)
            ->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->limit(500)->get(['id', 'workspace_id']));

        $started = 0;
        $skipped = 0;
        foreach ($due->groupBy('workspace_id') as $workspaceId => $group) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($group, $start, &$started, &$skipped): void {
                foreach ($group as $row) {
                    $schedule = ResearchSchedule::query()->find($row->id);
                    $campaign = $schedule === null ? null : SeoCampaign::query()->find($schedule->campaign_id);
                    if ($schedule === null || $campaign === null) {
                        continue;
                    }
                    $slot = $schedule->next_run_at;
                    $schedule->forceFill(['next_run_at' => $schedule->computeNextRun(now()), 'last_run_at' => now()])->save();
                    if ($campaign->status !== 'active') {
                        $skipped++;

                        continue;
                    }
                    try {
                        [$run] = $start->handle($campaign, 'scheduled', null, 'schedule:'.$schedule->id.':'.$slot?->format('YmdH'));
                        $schedule->forceFill(['last_research_run_id' => $run->id])->save();
                        $started++;
                    } catch (ApiException $e) {
                        // Quota exhausted / run in progress: skip this slot, try again next slot.
                        $skipped++;
                        $this->warn("Campaign {$campaign->id}: {$e->errorCode}");
                    }
                }
            });
        }

        $this->info("Started {$started} scheduled runs, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
