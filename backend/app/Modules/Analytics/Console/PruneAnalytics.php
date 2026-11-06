<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSetting;

/**
 * Retention: raw daily metric rows older than the workspace's
 * raw_retention_days (default 490 ≈ GSC's 16 months), sync runs and Copilot
 * history after 180 days. Aggregated snapshots, experiments, reports and
 * audit logs are kept.
 */
final class PruneAnalytics extends Command
{
    protected $signature = 'sitex:analytics:prune';

    protected $description = 'Delete analytics rows past their retention period';

    public function handle(TenantContext $context): int
    {
        $overrides = $context->bypass(fn () => AnalyticsSetting::query()->pluck('raw_retention_days', 'workspace_id'));
        $default = now('UTC')->subDays(490)->toDateString();
        $deleted = 0;
        foreach (['search_performance_daily', 'search_query_metrics', 'search_page_metrics'] as $table) {
            foreach ($overrides as $ws => $days) {
                $deleted += DB::table($table)->where('workspace_id', $ws)->where('date', '<', now('UTC')->subDays(max(90, (int) $days))->toDateString())->delete();
            }
            $deleted += DB::table($table)->whereNotIn('workspace_id', $overrides->keys()->all())->where('date', '<', $default)->delete();
        }
        $deleted += DB::table('search_query_page_metrics')->where('period_end', '<', now('UTC')->subDays(120)->toDateString())->delete();
        $deleted += DB::table('analytics_sync_runs')->where('created_at', '<', now()->subDays(180))->delete();
        $deleted += DB::table('copilot_queries')->where('created_at', '<', now()->subDays(180))->delete();
        $this->info("Pruned {$deleted} rows");

        return self::SUCCESS;
    }
}
