<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Domain\Site;
use Throwable;

/**
 * Base for queued connector work: loads the site inside the restored tenant
 * context and keeps the optional job_run (shown in the UI) up to date.
 */
abstract class ConnectorJob extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $siteId, public ?string $jobRunId = null)
    {
        parent::__construct();
        $this->onQueue('connector');
    }

    abstract protected function run(Site $site, ?JobRun $jobRun): void;

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        $jobRun = $this->jobRunId !== null ? JobRun::query()->find($this->jobRunId) : null;

        if ($site === null) {
            $jobRun?->forceFill(['status' => 'canceled', 'finished_at' => now()])->save();

            return;
        }

        $jobRun?->forceFill(['status' => 'running', 'started_at' => now(), 'progress' => 5])->save();

        try {
            $this->run($site, $jobRun);
            $jobRun?->forceFill(['status' => 'succeeded', 'progress' => 100, 'step' => null, 'finished_at' => now()])->save();
        } catch (ConnectorException $e) {
            $jobRun?->forceFill([
                'status' => 'failed', 'finished_at' => now(),
                'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        } catch (Throwable $e) {
            $jobRun?->forceFill([
                'status' => 'failed', 'finished_at' => now(),
                'error_code' => 'server_error', 'error_message' => __('api.server_error'),
            ])->save();
            throw $e;
        }
    }

    public static function startRun(string $type, Site $site, ?string $userId): JobRun
    {
        $run = new JobRun;
        $run->forceFill(['type' => $type, 'subject_type' => 'site', 'subject_id' => $site->id, 'created_by' => $userId])->save();

        return $run;
    }
}
