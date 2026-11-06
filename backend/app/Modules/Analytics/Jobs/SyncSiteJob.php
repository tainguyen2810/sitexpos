<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Analytics\Actions\SyncSearchConsole;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use Throwable;

/**
 * Runs one sync run, then chains the next chunk when the range is not
 * complete yet, and finally the analysis. Unique per site: two workers
 * never sync the same site concurrently.
 */
final class SyncSiteJob extends TenantJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public string $runId)
    {
        parent::__construct();
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return 'analytics-sync:'.$this->runId;
    }

    public function handle(SyncSearchConsole $sync): void
    {
        $run = AnalyticsSyncRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }
        $jobRun = $run->job_run_id !== null ? JobRun::query()->find($run->job_run_id) : null;
        $jobRun?->forceFill(['status' => 'running', 'started_at' => $jobRun->started_at ?? now(), 'progress' => 10])->save();
        try {
            $run = $sync->run($run);
        } catch (Throwable $e) {
            $run->forceFill(['status' => 'failed', 'error_code' => 'server_error', 'error_message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();
            $jobRun?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'server_error', 'error_message' => __('api.server_error')])->save();
            throw $e;
        }
        if ($run->status === 'completed') {
            $property = AnalyticsProperty::query()->where('site_id', $run->site_id)->where('provider', 'gsc')->first();
            $target = CarbonImmutable::now('UTC')->startOfDay()->subDays((int) config('sitex.analytics.lag_days', 3));
            $chain = (int) ($run->progress['chain'] ?? 0);
            $wanted = $target->subDays(AnalyticsConfig::backfillDays($this->workspaceId) - 1);
            $more = $chain < 20 && $run->rows_upserted > 0 && ($run->kind === 'backfill'
                ? ($property?->first_date !== null && CarbonImmutable::parse($property->first_date)->greaterThan($wanted))
                : ($property?->last_complete_date !== null && CarbonImmutable::parse($property->last_complete_date)->lessThan($target)));
            if ($more && $run->range_end !== null) {
                $next = new AnalyticsSyncRun;
                $next->forceFill(['site_id' => $run->site_id, 'provider' => 'gsc', 'kind' => $run->kind, 'status' => 'queued', 'triggered_by' => $run->triggered_by,
                    'job_run_id' => $run->job_run_id, 'progress' => ['chain' => $chain + 1],
                    'idempotency_key' => $run->idempotency_key !== null ? mb_substr($run->idempotency_key, 0, 90).':'.$run->range_end : null])->save();
                self::dispatch($next->id);

                return;
            }
            AnalyzeSiteJob::dispatch($run->site_id, $run->job_run_id);
        } elseif ($run->next_retry_at === null) {
            $jobRun?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => $run->error_code, 'error_message' => $run->error_message])->save();
        } else {
            $jobRun?->forceFill(['step' => 'retry_scheduled:'.$run->error_code])->save();
        }
    }
}
