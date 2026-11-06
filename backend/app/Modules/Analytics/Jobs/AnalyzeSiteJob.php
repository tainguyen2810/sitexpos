<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Analytics\Actions\AnalyzeSite;
use SiteXPost\Modules\Analytics\Actions\TrackExperiments;
use Throwable;

final class AnalyzeSiteJob extends TenantJob implements ShouldBeUnique
{
    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public string $siteId, public ?string $jobRunId = null)
    {
        parent::__construct();
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return 'analytics-analyze:'.$this->siteId;
    }

    public function handle(AnalyzeSite $analyze, TrackExperiments $experiments): void
    {
        $jobRun = $this->jobRunId !== null ? JobRun::query()->find($this->jobRunId) : null;
        $jobRun?->forceFill(['status' => 'running', 'progress' => 80, 'step' => 'analyze'])->save();
        try {
            $res = $analyze->run($this->siteId);
            $experiments->run($this->siteId);
            $jobRun?->forceFill(['status' => 'succeeded', 'progress' => 100, 'finished_at' => now(), 'step' => 'analyzed:'.($res['period_end'] ?? 'no_data')])->save();
        } catch (Throwable $e) {
            $jobRun?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'server_error', 'error_message' => __('api.server_error')])->save();
            throw $e;
        }
    }
}
