<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;

/**
 * A workflow type: an ordered list of idempotent steps. Each step's output
 * is checkpointed; a step that already succeeded is never re-run.
 */
interface WorkflowDefinition
{
    public function type(): string;

    /**
     * @param  array<string, mixed>  $input
     */
    public function queue(array $input): string;

    /**
     * @param  array<string, mixed>  $input
     */
    public function maxAttempts(array $input): int;

    /**
     * @return list<string>
     */
    public function steps(): array;

    /**
     * Run one step and return its (JSON-serialisable) output.
     *
     * @return array<string, mixed>
     *
     * @throws StepFailed
     */
    public function runStep(string $step, WorkflowRun $run, StepContext $context): array;

    /**
     * True when the step calls a paid external API. An interrupted paid step
     * (worker died mid-call) is only retried if allowsPaidRetry() is true.
     */
    public function isPaidStep(string $step): bool;

    public function allowsPaidRetry(WorkflowRun $run): bool;

    /**
     * @param  array<string, array<string, mixed>>  $outputs  step key → output
     * @return array<string, mixed>
     */
    public function output(WorkflowRun $run, array $outputs): array;
}
