<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Listeners;

use Illuminate\Queue\Events\JobFailed;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;
use Throwable;

/**
 * Every job that lands in failed_jobs also gets a dead-letter entry so the
 * Super Admin sees it next to failed workflows and can retry or discard it.
 */
final class RecordFailedJob
{
    public function handle(JobFailed $event): void
    {
        try {
            $payload = $event->job->payload();
            $command = is_array($payload['data'] ?? null) ? (string) ($payload['data']['command'] ?? '') : '';
            $workspaceId = preg_match('/"workspaceId";s:26:"([0-9a-z]{26})"/', $command, $m) === 1 ? $m[1] : null;

            $dead = new DeadLetter;
            $dead->forceFill([
                'workspace_id' => $workspaceId,
                'source' => 'job',
                'job_class' => mb_substr($event->job->resolveName(), 0, 191),
                'queue' => mb_substr((string) $event->job->getQueue(), 0, 32),
                'failed_job_uuid' => $event->job->uuid(),
                'error_code' => 'job_failed',
                'error_message' => SecretRedactor::redact(class_basename($event->exception).': '.$event->exception->getMessage()),
                'attempts' => $event->job->attempts(),
                'status' => 'open',
            ])->save();
        } catch (Throwable $e) {
            report($e); // never let dead-letter bookkeeping break the worker
        }
    }
}
