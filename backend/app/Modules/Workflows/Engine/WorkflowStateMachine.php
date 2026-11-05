<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Workflows\Domain\WorkflowEvent;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;

/**
 * Database-backed state machine. A transition is a single conditional
 * UPDATE … WHERE status = :from AND version = :version (compare-and-set):
 * if two workers race, exactly one wins and the other gets `false`.
 * Every successful transition is appended to workflow_events and mirrored
 * to the run's job_run (the generic progress record polled by the UI).
 */
final class WorkflowStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write atomically
     * @param  array<string, mixed>  $details  event details (no secrets)
     */
    public function transition(WorkflowRun $run, WorkflowStatus $to, string $event, array $attributes = [], array $details = []): bool
    {
        $from = $run->status;
        if (! $from->canTransitionTo($to)) {
            throw new InvalidTransition($from, $to);
        }

        $values = [...$attributes, 'status' => $to->value, 'version' => $run->version + 1, 'updated_at' => now()];
        $updated = WorkflowRun::query()->whereKey($run->id)
            ->where('status', $from->value)
            ->where('version', $run->version)
            ->update($this->serialise($run, $values));

        if ($updated !== 1) {
            return false;
        }

        $run->forceFill([...$attributes, 'status' => $to, 'version' => $run->version + 1])->syncOriginal();
        $this->record($run, $event, $from, $to, $details);
        $this->mirror($run);

        return true;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(WorkflowRun $run, string $event, ?WorkflowStatus $from = null, ?WorkflowStatus $to = null, array $details = []): void
    {
        $row = new WorkflowEvent;
        $row->forceFill([
            'workflow_run_id' => $run->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'details' => $details === [] ? null : $details,
        ])->save();
    }

    public function mirror(WorkflowRun $run, ?int $progress = null): void
    {
        if ($run->job_run_id === null) {
            return;
        }

        $status = match ($run->status) {
            WorkflowStatus::Pending, WorkflowStatus::RetryScheduled => 'queued',
            WorkflowStatus::Running => 'running',
            WorkflowStatus::Succeeded => 'succeeded',
            WorkflowStatus::Failed => 'failed',
            WorkflowStatus::Canceled => 'canceled',
        };

        $values = [
            'status' => $status,
            'step' => $run->current_step,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
        ];
        if ($progress !== null) {
            $values['progress'] = $progress;
        } elseif ($run->status === WorkflowStatus::Succeeded) {
            $values['progress'] = 100;
        }

        JobRun::query()->whereKey($run->job_run_id)->update([...$values, 'updated_at' => now()]);
    }

    /**
     * Apply the model's casts (arrays → JSON, dates) for a raw update.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function serialise(WorkflowRun $run, array $values): array
    {
        $probe = $run->replicate();
        $probe->forceFill($values);
        $out = [];
        foreach (array_keys($values) as $key) {
            $out[$key] = $probe->getAttributes()[$key] ?? null;
        }

        return $out;
    }
}
