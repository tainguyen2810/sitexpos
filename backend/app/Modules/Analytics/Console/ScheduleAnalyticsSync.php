<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Actions\StartSync;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Jobs\SyncSiteJob;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Daily incremental Search Console sync + resume of runs whose back-off
 * delay has passed. One run per site per day (idempotency key = date), so
 * overlapping scheduler ticks never create duplicates.
 */
final class ScheduleAnalyticsSync extends Command
{
    protected $signature = 'sitex:analytics:sync {--limit=200}';

    protected $description = 'Queue the daily Search Console sync and resume runs waiting for retry';

    public function handle(TenantContext $context, StartSync $start): int
    {
        $retries = $context->bypass(fn () => AnalyticsSyncRun::query()->whereIn('status', ['failed', 'partial'])->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())->limit(100)->get(['id', 'workspace_id']));
        foreach ($retries as $r) {
            $ws = Workspace::query()->find($r->workspace_id);
            if ($ws === null || $ws->status !== WorkspaceStatus::Active) {
                continue;
            }
            $context->runAs($ws, null, function () use ($r): void {
                AnalyticsSyncRun::query()->whereKey($r->id)->update(['status' => 'queued', 'next_retry_at' => null]);
                SyncSiteJob::dispatch($r->id);
            });
        }

        $today = now('UTC')->toDateString();
        $due = $context->bypass(fn () => AnalyticsProperty::query()->where('provider', 'gsc')->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<', now('UTC')->startOfDay()))
            ->orderBy('last_sync_at')->limit((int) $this->option('limit'))->get(['site_id', 'workspace_id']));
        $queued = 0;
        foreach ($due->groupBy('workspace_id') as $workspaceId => $props) {
            $ws = Workspace::query()->find($workspaceId);
            if ($ws === null || $ws->status !== WorkspaceStatus::Active) {
                continue;
            }
            $context->runAs($ws, null, function () use ($props, $start, $today, &$queued): void {
                foreach ($props as $p) {
                    try {
                        $res = $start->handle($p->site_id, 'incremental', null, 'daily:'.$today);
                        $queued += $res['created'] ? 1 : 0;
                    } catch (ApiException $e) {
                        $this->line("site {$p->site_id}: {$e->errorCode}");
                    }
                }
            });
        }
        $this->info("Retries: {$retries->count()}, daily syncs queued: {$queued}");

        return self::SUCCESS;
    }
}
