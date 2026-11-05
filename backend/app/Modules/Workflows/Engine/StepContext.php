<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Domain\WorkflowStep;

final readonly class StepContext
{
    /**
     * @param  array<string, array<string, mixed>>  $outputs  outputs of earlier steps
     */
    public function __construct(
        public WorkflowRun $run,
        public WorkflowStep $step,
        public array $outputs,
    ) {}

    /**
     * Idempotency key for an external call made by this step attempt. Stable
     * for a given (run, step, cycle, attempt): a duplicate job delivery reuses
     * it and the budget layer refuses to charge twice.
     */
    public function callKey(): string
    {
        return sprintf('wf:%s:%s:c%d:a%d', $this->run->id, $this->step->key, $this->run->cycle, $this->step->attempts);
    }

    public function cancelRequested(): bool
    {
        return WorkflowRun::query()->whereKey($this->run->id)->whereNotNull('cancel_requested_at')->exists();
    }
}
