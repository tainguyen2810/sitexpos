<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use SiteXPost\Modules\Workflows\Jobs\ProcessWorkflow;

/**
 * Time budget of the queue job that is executing a workflow run.
 *
 * One ProcessWorkflow job runs as many steps as it can, but the worker
 * kills it at the job timeout (300 s). Before 1.0.14 nothing tracked that
 * budget across steps, so a long article (write → claims → fact-check →
 * revise) was killed in the middle of a paid call and the run failed with
 * `interrupted_paid_call`. The engine starts this clock for every job; the
 * AI gateway refuses to START a provider call that cannot finish inside it
 * (it throws StepYield instead) and the engine yields between steps when
 * the budget is low. A yielded run continues in a fresh job from its
 * checkpoints — nothing that was already paid for is lost.
 *
 * Inactive outside the workflow engine (HTTP requests, other jobs).
 */
class JobClock
{
    private ?float $startedAt = null;

    private int $budget = 0;

    /** @param  float|null  $startedAt  unix time the job started (tests may back-date it) */
    public function start(int $budgetSeconds, ?float $startedAt = null): void
    {
        $this->startedAt = $startedAt ?? microtime(true);
        $this->budget = max(1, $budgetSeconds);
    }

    public function stop(): void
    {
        $this->startedAt = null;
        $this->budget = 0;
    }

    public function active(): bool
    {
        return $this->startedAt !== null;
    }

    /** Absolute deadline (unix time, seconds) or null when no job is running. */
    public function deadline(): ?float
    {
        return $this->startedAt === null ? null : $this->startedAt + $this->budget;
    }

    public function remaining(): ?float
    {
        $deadline = $this->deadline();

        return $deadline === null ? null : $deadline - microtime(true);
    }

    /**
     * Would a piece of work of this length fit into a fresh job? If not,
     * yielding cannot help (it would loop), so the caller must not yield.
     */
    public function fitsFreshJob(float $seconds): bool
    {
        return $this->active() && $seconds < $this->budget;
    }

    /** Budget of one job: below the job timeout, with a margin for DB work. */
    public static function jobBudgetSeconds(): int
    {
        $timeout = ProcessWorkflow::TIMEOUT;

        return max(30, min($timeout - 15, (int) config('sitex.workflows.job_budget_seconds', 270)));
    }
}
