<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Domain\WorkflowStep;
use SiteXPost\Modules\Workflows\Enums\StepStatus;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;
use SiteXPost\Modules\Workflows\Jobs\ProcessWorkflow;
use Throwable;

/**
 * Runs durable workflows (ARCHITECTURE.md §10.1). All state is in the
 * database; queue jobs only carry the run id, so duplicate deliveries,
 * worker restarts and lost jobs are all safe:
 * - claim = CAS transition with a lease (locked_by / locked_until);
 * - succeeded steps are checkpoints and never run twice;
 * - recovery re-dispatches runs whose lease expired or whose job was lost;
 * - an interrupted PAID step is not retried unless the definition allows it.
 */
final class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly WorkflowStateMachine $machine,
        private readonly ConcurrencyLimiter $limiter,
    ) {}

    /**
     * Create (or return the existing idempotent) run and queue it.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: WorkflowRun, 1: bool} [run, created]
     *
     * @throws IdempotencyConflict
     */
    public function start(string $type, array $input, ?string $idempotencyKey = null, ?string $createdBy = null): array
    {
        $definition = $this->registry->get($type);
        $hash = hash('sha256', $type.'|'.json_encode($input));

        if ($idempotencyKey !== null && ($existing = $this->findByKey($idempotencyKey)) !== null) {
            return [$this->assertSameRequest($existing, $hash), false];
        }

        try {
            $run = DB::transaction(function () use ($definition, $type, $input, $idempotencyKey, $createdBy, $hash): WorkflowRun {
                $jobRun = new JobRun;
                $jobRun->forceFill(['type' => 'workflow.'.$type, 'subject_type' => 'workflow_run', 'created_by' => $createdBy])->save();

                $run = new WorkflowRun;
                $run->forceFill([
                    'type' => $type,
                    'queue' => $definition->queue($input),
                    'status' => WorkflowStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $hash,
                    'input' => $input,
                    'max_attempts' => max(1, $definition->maxAttempts($input)),
                    'job_run_id' => $jobRun->id,
                    'created_by' => $createdBy,
                ])->save();
                $jobRun->forceFill(['subject_id' => $run->id])->save();

                foreach ($definition->steps() as $i => $key) {
                    $step = new WorkflowStep;
                    $step->forceFill(['workflow_run_id' => $run->id, 'key' => $key, 'sequence' => $i + 1])->save();
                }
                $this->machine->record($run, 'created', null, WorkflowStatus::Pending, ['type' => $type]);

                return $run;
            });
        } catch (QueryException $e) {
            // Same idempotency key submitted concurrently: return the winner.
            if ($idempotencyKey !== null && ($existing = $this->findByKey($idempotencyKey)) !== null) {
                return [$this->assertSameRequest($existing, $hash), false];
            }
            throw $e;
        }

        $this->dispatch($run);

        return [$run, true];
    }

    public function dispatch(WorkflowRun $run, int $delaySeconds = 0): void
    {
        WorkflowRun::query()->whereKey($run->id)->update(['dispatched_at' => now()]);
        $job = (new ProcessWorkflow($run->id))->onQueue($run->queue);
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
        dispatch($job)->afterCommit();
    }

    /**
     * Execute a run inside the restored tenant context (called by the job).
     */
    public function execute(string $runId, string $workerToken): void
    {
        $run = WorkflowRun::query()->find($runId);
        if ($run === null || $run->status->isTerminal() || $run->status === WorkflowStatus::Failed) {
            return;
        }

        if ($run->status === WorkflowStatus::Running && $run->locked_until !== null && $run->locked_until->isFuture() && $run->locked_by !== $workerToken) {
            return; // another worker holds the lease (it will honour a cancel request)
        }

        if ($run->cancel_requested_at !== null) {
            $this->finishCanceled($run, null);

            return;
        }

        if ($run->status === WorkflowStatus::RetryScheduled && $run->next_attempt_at !== null && $run->next_attempt_at->isAfter(now()->addSecond())) {
            return; // early duplicate delivery — the delayed job will come
        }
        $lease = max(60, (int) config('sitex.workflows.lease_seconds', 360));
        $slot = $this->limiter->acquire($run->workspace_id, $lease);
        if ($slot === null) {
            $this->defer($run);

            return;
        }

        try {
            $this->claimAndRun($run, $workerToken, $lease);
        } finally {
            $slot->release();
        }
    }

    private function claimAndRun(WorkflowRun $run, string $token, int $lease): void
    {
        $recovered = $run->status === WorkflowStatus::Running;
        $claimed = $this->machine->transition($run, WorkflowStatus::Running, $recovered ? 'recovered' : 'started', [
            'locked_by' => $token,
            'locked_until' => now()->addSeconds($lease),
            'attempts' => $run->attempts + 1,
            'started_at' => $run->started_at ?? now(),
            'next_attempt_at' => null,
            'error_code' => null,
            'error_message' => null,
        ], ['attempt' => $run->attempts + 1]);

        if (! $claimed) {
            return;
        }

        $definition = $this->registry->get($run->type);
        /** @var array<string, WorkflowStep> $steps */
        $steps = $run->steps()->get()->keyBy('key')->all();
        $keys = $definition->steps();
        $outputs = [];

        foreach ($keys as $index => $key) {
            $step = $steps[$key] ?? null;
            if ($step === null) {
                continue;
            }
            if ($step->status === StepStatus::Succeeded) {
                $outputs[$key] = $step->output ?? [];

                continue;
            }

            if (WorkflowRun::query()->whereKey($run->id)->whereNotNull('cancel_requested_at')->exists()) {
                $this->finishCanceled($run, $step);

                return;
            }

            if ($step->status === StepStatus::Running && $definition->isPaidStep($key) && ! $definition->allowsPaidRetry($run)) {
                // The worker died during a paid call: the provider may have billed it.
                $this->fail($run, $step, new StepFailed('interrupted_paid_call', __('workflows.errors.interrupted_paid_call')));

                return;
            }

            $step->forceFill(['status' => StepStatus::Running, 'attempts' => $step->attempts + 1, 'started_at' => now(), 'error_code' => null, 'error_message' => null])->save();
            WorkflowRun::query()->whereKey($run->id)->update(['current_step' => $key, 'locked_until' => now()->addSeconds($lease)]);
            $run->current_step = $key;
            $this->machine->mirror($run, (int) floor($index * 100 / max(1, count($keys))));

            try {
                $output = $definition->runStep($key, $run, new StepContext($run, $step, $outputs));
            } catch (StepFailed $e) {
                $this->handleFailure($run, $step, $e, $definition);

                return;
            } catch (Throwable $e) {
                report($e);
                $this->handleFailure($run, $step, new StepFailed('server_error', __('api.server_error'), retryable: true), $definition);

                return;
            }

            $step->forceFill(['status' => StepStatus::Succeeded, 'output' => $output, 'finished_at' => now()])->save();
            $outputs[$key] = $output;
        }

        $run->refresh();
        $this->machine->transition($run, WorkflowStatus::Succeeded, 'succeeded', [
            'output' => $definition->output($run, $outputs),
            'finished_at' => now(),
            'locked_by' => null,
            'locked_until' => null,
            'current_step' => null,
        ]);
    }

    private function handleFailure(WorkflowRun $run, WorkflowStep $step, StepFailed $e, WorkflowDefinition $definition): void
    {
        $run->refresh();
        $paidBlocked = $e->requiresPaidRetry && ! $definition->allowsPaidRetry($run);

        if ($e->retryable || $e->requiresPaidRetry) {
            if (! $paidBlocked && $run->attempts < $run->max_attempts) {
                $delay = $this->backoffSeconds($run->attempts);
                $step->forceFill(['status' => StepStatus::Pending, 'error_code' => $e->errorCode, 'error_message' => SecretRedactor::redact($e->getMessage())])->save();
                if ($this->machine->transition($run, WorkflowStatus::RetryScheduled, 'retry_scheduled', [
                    'next_attempt_at' => now()->addSeconds($delay),
                    'locked_by' => null,
                    'locked_until' => null,
                    'error_code' => $e->errorCode,
                    'error_message' => SecretRedactor::redact($e->getMessage()),
                ], ['delay_seconds' => $delay, 'attempt' => $run->attempts, 'error_code' => $e->errorCode])) {
                    $this->dispatch($run, $delay);
                }

                return;
            }
        }

        $this->fail($run, $step, $paidBlocked ? new StepFailed($e->errorCode, $e->getMessage()) : $e);
    }

    private function fail(WorkflowRun $run, WorkflowStep $step, StepFailed $e): void
    {
        $message = SecretRedactor::redact($e->getMessage());
        $step->forceFill(['status' => StepStatus::Failed, 'error_code' => $e->errorCode, 'error_message' => $message, 'finished_at' => now()])->save();
        $run->refresh();

        if ($this->machine->transition($run, WorkflowStatus::Failed, 'failed', [
            'finished_at' => now(),
            'locked_by' => null,
            'locked_until' => null,
            'error_code' => $e->errorCode,
            'error_message' => $message,
        ], ['error_code' => $e->errorCode, 'attempts' => $run->attempts])) {
            $dead = new DeadLetter;
            $dead->forceFill([
                'workspace_id' => $run->workspace_id,
                'source' => 'workflow',
                'workflow_run_id' => $run->id,
                'job_class' => $run->type,
                'queue' => $run->queue,
                'error_code' => $e->errorCode,
                'error_message' => $message,
                'attempts' => $run->attempts,
                'status' => 'open',
            ])->save();
        }
    }

    private function finishCanceled(WorkflowRun $run, ?WorkflowStep $step): void
    {
        $step?->forceFill(['status' => StepStatus::Canceled, 'finished_at' => now()])->save();
        WorkflowStep::query()->where('workflow_run_id', $run->id)->whereIn('status', [StepStatus::Pending->value, StepStatus::Running->value])
            ->update(['status' => StepStatus::Canceled->value, 'updated_at' => now()]);

        $this->machine->transition($run, WorkflowStatus::Canceled, 'canceled', [
            'finished_at' => now(), 'locked_by' => null, 'locked_until' => null, 'current_step' => null,
        ]);
    }

    private function defer(WorkflowRun $run): void
    {
        $delay = max(1, (int) config('sitex.workflows.defer_seconds', 10));
        WorkflowRun::query()->whereKey($run->id)->update(['next_attempt_at' => now()->addSeconds($delay)]);
        $this->dispatch($run, $delay);
    }

    /**
     * Request cancellation. Queued runs stop immediately; a running run stops
     * at the next step boundary (an in-flight provider call is not aborted).
     */
    public function cancel(WorkflowRun $run, ?string $userId): bool
    {
        if (! $run->status->isActive()) {
            return false;
        }

        WorkflowRun::query()->whereKey($run->id)->whereNull('cancel_requested_at')->update(['cancel_requested_at' => now()]);
        $this->machine->record($run, 'cancel_requested', $run->status, null, ['by' => $userId]);
        $run->refresh();

        if (in_array($run->status, [WorkflowStatus::Pending, WorkflowStatus::RetryScheduled], true)) {
            $this->finishCanceled($run, null);
        }

        return true;
    }

    /**
     * Manual retry of a failed run: a new cycle (fresh attempt budget and new
     * idempotency keys), succeeded steps are kept.
     */
    public function retry(WorkflowRun $run, ?string $userId): bool
    {
        if ($run->status !== WorkflowStatus::Failed) {
            return false;
        }

        $ok = $this->machine->transition($run, WorkflowStatus::Pending, 'manual_retry', [
            'attempts' => 0,
            'cycle' => $run->cycle + 1,
            'error_code' => null,
            'error_message' => null,
            'finished_at' => null,
            'cancel_requested_at' => null,
            'next_attempt_at' => null,
        ], ['by' => $userId]);

        if ($ok) {
            WorkflowStep::query()->where('workflow_run_id', $run->id)->where('status', '!=', StepStatus::Succeeded->value)
                ->update(['status' => StepStatus::Pending->value, 'attempts' => 0, 'updated_at' => now()]);
            DeadLetter::query()->where('workflow_run_id', $run->id)->where('status', 'open')
                ->update(['status' => 'retried', 'resolved_by' => $userId, 'resolved_at' => now(), 'updated_at' => now()]);
            $this->dispatch($run);
        }

        return $ok;
    }

    /**
     * Re-dispatch runs that lost their worker or their queue job (restart,
     * crash, Redis flush). Safe to run often: claims are compare-and-set.
     */
    public function recoverable(WorkflowRun $run): bool
    {
        $stale = max(60, (int) config('sitex.workflows.stale_pending_seconds', 600));

        return match ($run->status) {
            WorkflowStatus::Running => $run->locked_until === null || $run->locked_until->isPast(),
            WorkflowStatus::RetryScheduled => $run->next_attempt_at === null || $run->next_attempt_at->lt(now()->subMinute()),
            WorkflowStatus::Pending => ($run->dispatched_at === null || $run->dispatched_at->lt(now()->subSeconds($stale)))
                && ($run->next_attempt_at === null || $run->next_attempt_at->lt(now()->subMinute())),
            default => false,
        };
    }

    public function recover(WorkflowRun $run): void
    {
        $this->machine->record($run, 'recovery_dispatched', $run->status, null, ['locked_until' => $run->locked_until?->toIso8601ZuluString()]);
        $this->dispatch($run);
    }

    public function backoffSeconds(int $attempt): int
    {
        $base = max(1, (int) config('sitex.workflows.backoff_base_seconds', 15));
        $max = max($base, (int) config('sitex.workflows.backoff_max_seconds', 900));
        $delay = min($max, $base * (2 ** max(0, $attempt - 1)));

        return $delay + random_int(0, max(1, intdiv($base, 2)));
    }

    private function findByKey(string $key): ?WorkflowRun
    {
        return WorkflowRun::query()->where('idempotency_key', $key)->first();
    }

    private function assertSameRequest(WorkflowRun $run, string $hash): WorkflowRun
    {
        if ($run->request_hash !== $hash) {
            throw new IdempotencyConflict('Idempotency key reused with a different request.');
        }

        return $run;
    }

    /**
     * Exposed for tests/diagnostics.
     */
    public function slot(string $workspaceId): ?Lock
    {
        return $this->limiter->acquire($workspaceId, 60);
    }
}
