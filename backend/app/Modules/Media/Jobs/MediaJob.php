<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use Throwable;

/**
 * Base for media queue work (`media` queue): keeps the optional job_run
 * the UI polls in sync. One try: the work is idempotent and a failure is
 * shown to the user, who retries explicitly (never an automatic paid loop).
 */
abstract class MediaJob extends TenantJob
{
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public ?string $jobRunId = null)
    {
        parent::__construct();
        $this->onQueue('media');
    }

    abstract protected function run(?JobRun $run): void;

    public function handle(): void
    {
        $run = $this->jobRunId !== null ? JobRun::query()->find($this->jobRunId) : null;
        $run?->forceFill(['status' => 'running', 'started_at' => now(), 'progress' => 5])->save();
        try {
            $this->run($run);
            $run?->refresh();
            if ($run !== null && $run->status === 'running') {
                $run->forceFill(['status' => 'succeeded', 'progress' => 100, 'finished_at' => now()])->save();
            }
        } catch (Throwable $e) {
            $run?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'server_error', 'error_message' => __('api.server_error')])->save();
            throw $e;
        }
    }

    public static function startRun(string $type, string $subjectType, string $subjectId, ?string $userId): JobRun
    {
        $run = new JobRun;
        $run->forceFill(['type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'created_by' => $userId])->save();

        return $run;
    }

    protected static function failRun(?JobRun $run, string $code, string $message): void
    {
        $run?->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_code' => $code, 'error_message' => mb_substr($message, 0, 500)])->save();
    }
}
