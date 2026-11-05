<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Workflows;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Writer\Actions\ArticleWorkflows;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Pipeline\ArticleContext;
use SiteXPost\Modules\Writer\Pipeline\OutlineBuilder;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;

/**
 * Approved brief → outline (then wait for approval, or continue to the
 * draft when the review mode does not require outline approval).
 */
final class OutlineWorkflow extends ArticleWorkflow
{
    public const TYPE = 'writer.outline';

    protected const STEPS = ['prepare' => 5, 'outline' => 60, 'handoff' => 95];

    protected const PAID = ['outline'];

    public function __construct(ArticleStateMachine $machine, private readonly OutlineBuilder $builder, private readonly ArticleContext $context)
    {
        parent::__construct($machine);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function queue(array $input): string
    {
        return 'writing';
    }

    protected function step(string $step, Article $a, WorkflowRun $run, StepContext $ctx): array
    {
        return match ($step) {
            'prepare' => $this->prepare($a),
            'outline' => $this->outline($a, $ctx, $run),
            'handoff' => $this->handoff($a, $ctx, $run),
            default => throw new StepFailed('unknown_step', $step),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(Article $a): array
    {
        $brief = $this->context->approvedBrief($a);
        if ($brief === null) {
            throw new StepFailed('brief_not_approved', __('writer.errors.brief_not_approved'));
        }
        $this->moveTo($a, ArticleStatus::Outlining);

        return ['brief_id' => $brief->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function outline(Article $a, StepContext $ctx, WorkflowRun $run): array
    {
        $brief = $this->context->approvedBrief($a) ?? throw new StepFailed('brief_not_approved', __('writer.errors.brief_not_approved'));
        $outline = $this->builder->build($a, $brief, $ctx->callKey(), $run->id);

        return ['outline_id' => $outline->id, 'sections' => count($outline->sections), 'warnings' => $outline->warnings];
    }

    /**
     * @return array<string, mixed>
     */
    private function handoff(Article $a, StepContext $ctx, WorkflowRun $run): array
    {
        if ($a->review_mode === 'step_by_step') {
            return ['waiting_for' => 'outline_approval'];
        }
        $outline = ArticleOutline::query()->findOrFail((string) $ctx->outputs['outline']['outline_id']);
        $outline->forceFill(['status' => 'approved', 'approved_at' => now(), 'warnings' => [...($outline->warnings ?? []), 'auto_approved_'.$a->review_mode]])->save();
        $next = app(ArticleWorkflows::class)->start($a->refresh(), 'draft', null, 'chain:'.$run->id.':draft', chained: true);

        return ['chained' => $next->id];
    }
}
