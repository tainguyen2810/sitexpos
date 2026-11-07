<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Workflows;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Writer\Actions\ArticleWorkflows;
use SiteXPost\Modules\Writer\Actions\Batches;
use SiteXPost\Modules\Writer\Ai\AiCaller;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Pipeline\BriefBuilder;
use SiteXPost\Modules\Writer\Pipeline\ResearchEngine;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Evidence research → (follow-up web lookup when evidence is thin) →
 * dossier → (evidence gate) → content brief.
 */
final class ResearchWorkflow extends ArticleWorkflow
{
    public const TYPE = 'writer.research';

    protected const STEPS = ['prepare' => 2, 'questions' => 10, 'discover' => 20, 'retrieve' => 40, 'extract' => 55, 'lookup' => 68, 'dossier' => 75, 'brief' => 90, 'handoff' => 98];

    protected const PAID = ['questions', 'discover', 'extract', 'lookup', 'brief'];

    public function __construct(
        ArticleStateMachine $machine,
        private readonly ResearchEngine $engine,
        private readonly BriefBuilder $briefs,
        private readonly WriterSettings $settings,
    ) {
        parent::__construct($machine);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function queue(array $input): string
    {
        return 'research';
    }

    protected function step(string $step, Article $a, WorkflowRun $run, StepContext $ctx): array
    {
        $dossier = fn (): ResearchDossier => ResearchDossier::query()->findOrFail((string) ($ctx->outputs['prepare']['dossier_id'] ?? ''));

        return match ($step) {
            'prepare' => $this->prepare($a),
            'questions' => $this->engine->questions($a, $dossier(), $ctx->callKey(), $run->id),
            'discover' => $this->engine->discover($a, $dossier(), $ctx->callKey(), $run->id),
            'retrieve' => $this->retrieve($a, $dossier(), $ctx, $run),
            'extract' => $this->engine->extract($a, $dossier(), $ctx->callKey(), $run->id),
            'lookup' => $this->lookup($a, $dossier(), $ctx, $run),
            'dossier' => $this->finalize($a, $dossier()),
            'brief' => $this->brief($a, $dossier(), $ctx, $run),
            'handoff' => $this->handoff($a, $run),
            default => throw new StepFailed('unknown_step', $step),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(Article $a): array
    {
        $this->moveTo($a, ArticleStatus::Researching, ['last_error_code' => null, 'last_error_message' => null]);
        $d = $this->engine->dossierFor($a);

        return ['dossier_id' => $d->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieve(Article $a, ResearchDossier $d, StepContext $ctx, WorkflowRun $run): array
    {
        $out = $this->engine->retrieve($a, $d);
        app(AiCaller::class)->recordFetches($a, $ctx->callKey().':fetch', $run->id, (int) ($out['fetches'] ?? 0));

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function lookup(Article $a, ResearchDossier $d, StepContext $ctx, WorkflowRun $run): array
    {
        $out = $this->engine->lookup($a, $d, $ctx->callKey(), $run->id);
        app(AiCaller::class)->recordFetches($a, $ctx->callKey().':fetch', $run->id, (int) ($out['fetches'] ?? 0));

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize(Article $a, ResearchDossier $d): array
    {
        $out = $this->engine->finalize($d);
        $this->moveTo($a, ArticleStatus::ResearchComplete);

        // Evidence gate: without enough verified evidence the writer can only
        // produce "cần xác minh" filler. Stop here, say what is missing and
        // let the user add sources / enable web search and research again.
        $min = max(0, $this->settings->int('min_evidence_items', $a->workspace_id));
        if ((int) $out['evidence'] < $min) {
            $message = $this->engine->shortfall($a, $d, $min);
            $a->forceFill(['blocked_reason' => 'insufficient_evidence', 'last_error_code' => 'insufficient_evidence', 'last_error_message' => $message])->save();
            app(Batches::class)->articleFinished($a->refresh());
            throw new StepFailed('insufficient_evidence', $message);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(Article $a, ResearchDossier $d, StepContext $ctx, WorkflowRun $run): array
    {
        $brief = $this->briefs->build($a, $d, $ctx->callKey(), $run->id);
        $this->moveTo($a, ArticleStatus::BriefReady);

        return ['brief_id' => $brief->id, 'warnings' => $brief->warnings];
    }

    /**
     * Autonomous / "final only" review: the brief is approved by the system
     * and the outline workflow starts. Step-by-step / brief review: stop here
     * and wait for the human.
     *
     * @return array<string, mixed>
     */
    private function handoff(Article $a, WorkflowRun $run): array
    {
        if ($a->review_mode !== 'final_only') {
            return ['waiting_for' => 'brief_approval'];
        }
        $brief = ContentBrief::query()->where('article_id', $a->id)->orderByDesc('version')->firstOrFail();
        if ($brief->status !== 'approved') {
            $brief->forceFill(['status' => 'approved', 'approved_at' => now(), 'warnings' => [...($brief->warnings ?? []), 'auto_approved_final_only']])->save();
        }
        $next = app(ArticleWorkflows::class)->start($a->refresh(), 'outline', null, 'chain:'.$run->id.':outline', chained: true);

        return ['chained' => $next->id];
    }
}
