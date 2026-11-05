<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Sites\Jobs\HealthCheckSite;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Periodic connection health (scheduled every few minutes, each site is
 * checked at most once per `health_interval_minutes`).
 */
final class ScheduleHealthChecks extends Command
{
    protected $signature = 'sitex:sites:health {--all : ignore the interval}';

    protected $description = 'Queue health checks for connected WordPress sites';

    public function handle(TenantContext $context): int
    {
        $interval = (int) config('sitex.connector.health_interval_minutes', 30);

        // Reviewed scope bypass: system scheduler, read-only selection of ids.
        $sites = $context->bypass(fn () => Site::query()
            ->whereIn('status', [SiteStatus::Connected->value, SiteStatus::Degraded->value])
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($w) => $w->whereNull('last_health_at')->orWhere('last_health_at', '<', now()->subMinutes($interval))))
            ->get(['id', 'workspace_id']));

        $count = 0;
        foreach ($sites->groupBy('workspace_id') as $workspaceId => $group) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($group, &$count): void {
                foreach ($group as $site) {
                    HealthCheckSite::dispatch((string) $site->id, null);
                    $count++;
                }
            });
        }

        $this->info("Queued {$count} health checks.");

        return self::SUCCESS;
    }
}
