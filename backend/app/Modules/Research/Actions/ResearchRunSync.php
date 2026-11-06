<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Support\QuotaManager;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;

/**
 * Mirrors the durable workflow state onto the research run (failed /
 * canceled are terminal in the engine without a callback) and settles the
 * quota reservation. Called on read, by cancel/retry and by the scheduler.
 */
final class ResearchRunSync
{
    public function __construct(private readonly QuotaManager $quota, private readonly WorkflowEngine $engine) {}

    public function sync(ResearchRun $run): ResearchRun
    {
        if (! $run->isActive() || $run->workflow_run_id === null) {
            return $run;
        }
        $wf = WorkflowRun::query()->find($run->workflow_run_id);
        if ($wf === null) {
            return $run;
        }

        if ($wf->status === WorkflowStatus::Failed) {
            $run->forceFill(['status' => 'failed', 'error_code' => $wf->error_code, 'error_message' => $wf->error_message, 'finished_at' => $wf->finished_at ?? now()])->save();
            $this->quota->settle($run, false);
        } elseif ($wf->status === WorkflowStatus::Canceled) {
            $run->forceFill(['status' => 'canceled', 'finished_at' => $wf->finished_at ?? now()])->save();
            $this->quota->settle($run, false);
        } elseif ($wf->status === WorkflowStatus::RetryScheduled && $run->status !== 'running') {
            $run->forceFill(['status' => 'running'])->save();
        }

        return $run;
    }

    public function cancel(ResearchRun $run, ?string $userId): ResearchRun
    {
        $this->sync($run);
        $wf = $run->workflow_run_id !== null ? WorkflowRun::query()->find($run->workflow_run_id) : null;
        if (! $run->isActive() || $wf === null || ! $this->engine->cancel($wf, $userId)) {
            throw ApiException::conflict('invalid_state_transition', __('research.errors.not_cancelable'));
        }
        Audit::record('research.run.cancel_requested', $run);

        return $this->sync($run->refresh());
    }

    public function retry(ResearchRun $run, ?string $userId): ResearchRun
    {
        $this->sync($run);
        $wf = $run->workflow_run_id !== null ? WorkflowRun::query()->find($run->workflow_run_id) : null;
        if ($run->status !== 'failed' || $wf === null || $wf->status !== WorkflowStatus::Failed) {
            throw ApiException::conflict('invalid_state_transition', __('research.errors.not_retryable'));
        }
        $this->quota->reserveRun($run->workspace_id, function () use ($run): void {
            $run->forceFill(['status' => 'queued', 'error_code' => null, 'error_message' => null, 'finished_at' => null, 'quota_reserved' => true])->save();
        });
        $this->engine->retry($wf, $userId);
        Audit::record('research.run.retried', $run);

        return $run->refresh();
    }
}
