<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Jobs\SyncSiteJob;
use SiteXPost\Modules\Research\Contracts\SearchConsoleAccess;

/**
 * Queue a Search Console sync (manual button or scheduler). One active run
 * per site; repeated requests with the same idempotency key return the
 * same run.
 */
final class StartSync
{
    public function __construct(private readonly SearchConsoleAccess $gsc) {}

    /**
     * @return array{run: AnalyticsSyncRun, job_run: JobRun|null, created: bool}
     */
    public function handle(string $siteId, string $kind, ?string $userId, ?string $idempotencyKey = null): array
    {
        $status = $this->gsc->status($siteId);
        if (! $status['connected']) {
            throw ApiException::conflict('gsc_not_connected', __('analytics.errors.gsc_not_connected'));
        }
        if ($status['status'] !== 'active' || $status['property_url'] === null) {
            throw ApiException::conflict('gsc_needs_property', __('analytics.errors.gsc_needs_property'));
        }
        $key = $idempotencyKey !== null ? 'gsc:'.$siteId.':'.mb_substr($idempotencyKey, 0, 80) : null;
        if ($key !== null && ($existing = AnalyticsSyncRun::query()->where('idempotency_key', $key)->first()) !== null) {
            return ['run' => $existing, 'job_run' => $existing->job_run_id !== null ? JobRun::query()->find($existing->job_run_id) : null, 'created' => false];
        }
        $active = AnalyticsSyncRun::query()->where('site_id', $siteId)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHour())->first();
        if ($active !== null) {
            throw ApiException::conflict('sync_in_progress', __('analytics.errors.sync_in_progress'));
        }
        $jobRun = new JobRun;
        $jobRun->forceFill(['type' => 'analytics.gsc_sync', 'subject_type' => 'site', 'subject_id' => $siteId, 'created_by' => $userId])->save();
        $run = new AnalyticsSyncRun;
        $run->forceFill(['site_id' => $siteId, 'provider' => 'gsc', 'kind' => $kind, 'status' => 'queued', 'triggered_by' => $userId, 'job_run_id' => $jobRun->id, 'idempotency_key' => $key])->save();
        SyncSiteJob::dispatch($run->id);

        return ['run' => $run->refresh(), 'job_run' => $jobRun->refresh(), 'created' => true];
    }
}
