<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Workflows;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Writer\Actions\Batches;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Events\ArticleDraftReady;
use SiteXPost\Modules\Writer\Pipeline\AutoApprover;
use SiteXPost\Modules\Writer\Pipeline\ClaimExtractor;
use SiteXPost\Modules\Writer\Pipeline\FactChecker;
use SiteXPost\Modules\Writer\Pipeline\QualityAuditor;
use SiteXPost\Modules\Writer\Pipeline\Reviser;
use SiteXPost\Modules\Writer\Pipeline\SectionWriter;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Approved outline → section-by-section draft → claims → fact-check →
 * bounded automatic revision → quality audit → human review queue.
 * Never APPROVED automatically, never published (Phase 6).
 */
final class DraftWorkflow extends ArticleWorkflow
{
    public const TYPE = 'writer.draft';

    protected const STEPS = ['prepare' => 2, 'write' => 40, 'assemble' => 45, 'claims' => 55, 'factcheck' => 70, 'revise' => 85, 'quality' => 95, 'finalize' => 99];

    protected const PAID = ['write', 'claims', 'factcheck', 'revise', 'quality'];

    public function __construct(
        ArticleStateMachine $machine,
        private readonly SectionWriter $writer,
        private readonly ClaimExtractor $claims,
        private readonly FactChecker $checker,
        private readonly Reviser $reviser,
        private readonly QualityAuditor $quality,
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
        return 'writing';
    }

    protected function step(string $step, Article $a, WorkflowRun $run, StepContext $ctx): array
    {
        $outline = fn (): ArticleOutline => ArticleOutline::query()->findOrFail((string) ($ctx->outputs['prepare']['outline_id'] ?? ''));
        $current = fn (): ArticleVersion => ArticleVersion::query()->findOrFail((string) $a->refresh()->current_version_id);

        return match ($step) {
            'prepare' => $this->prepare($a),
            'write' => $this->writer->write($a, $outline(), $ctx->callKey(), $run->id),
            'assemble' => $this->assemble($a, $outline(), $run),
            'claims' => $this->extract($a, $current(), $ctx, $run),
            'factcheck' => ['run_id' => $this->checker->check($a, $current(), 0, $ctx->callKey(), $run->id)->id],
            'revise' => self::reviseLoop($a, $ctx, $run, $this->reviser, $this->claims, $this->checker, $this->machine, $this->settings),
            'quality' => $this->audit($a, $current(), $ctx, $run),
            'finalize' => $this->finalize($a, $run),
            default => throw new StepFailed('unknown_step', $step),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(Article $a): array
    {
        $outline = ArticleOutline::query()->where('article_id', $a->id)->where('status', 'approved')->orderByDesc('version')->first()
            ?? throw new StepFailed('outline_not_approved', __('writer.errors.outline_not_approved'));
        $this->moveTo($a, ArticleStatus::Writing);

        return ['outline_id' => $outline->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(Article $a, ArticleOutline $outline, WorkflowRun $run): array
    {
        // Replay-safe: a draft version made by an earlier attempt of this run is reused.
        $v = ArticleVersion::query()->where('article_id', $a->id)->where('source', 'ai_draft')->where('created_at', '>=', $run->created_at)->orderByDesc('number')->first()
            ?? $this->writer->assemble($a, $outline);

        return ['version_id' => $v->id, 'number' => $v->number, 'words' => $v->word_count];
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(Article $a, ArticleVersion $v, StepContext $ctx, WorkflowRun $run): array
    {
        $this->moveTo($a, ArticleStatus::FactChecking);

        return $this->claims->extract($a, $v, null, $ctx->callKey(), $run->id);
    }

    /**
     * Bounded revision loop: at most N rounds (Super Admin policy); each round
     * fixes only sections with problem claims, then re-extracts and re-checks
     * only those sections. No infinite loop: the round counter is persisted
     * on the article and the loop also stops when nothing changes.
     *
     * @return array<string, mixed>
     */
    public static function reviseLoop(Article $a, StepContext $ctx, WorkflowRun $run, Reviser $reviser, ClaimExtractor $claims, FactChecker $checker,
        ArticleStateMachine $machine, WriterSettings $settings): array
    {
        $max = max(0, min(5, $settings->int('max_revision_rounds', $a->workspace_id)));
        $rounds = [];
        for ($round = $a->revision_rounds + 1; $round <= $max; $round++) {
            $a->refresh();
            $v = ArticleVersion::query()->findOrFail((string) $a->current_version_id);
            if (Reviser::problems($v)->isEmpty()) {
                break;
            }
            $machine->move($a, ArticleStatus::Revising);
            $res = $reviser->revise($a, $v, $round, $ctx->callKey().':r'.$round, $run->id);
            Article::query()->whereKey($a->id)->update(['revision_rounds' => $round]);
            $machine->move($a, ArticleStatus::FactChecking);
            $rounds[] = ['round' => $round, 'changed' => $res['changed'], 'rejected' => $res['rejected']];
            if ($res['version'] === null) {
                break; // nothing could be fixed automatically → human review
            }
            $claims->extract($a, $res['version'], $res['changed'], $ctx->callKey().':r'.$round.':claims', $run->id);
            $checker->check($a, $res['version'], $round, $ctx->callKey().':r'.$round.':fc', $run->id, $res['changed']);
        }
        $a->refresh();

        return ['rounds' => $rounds, 'max_rounds' => $max, 'remaining_problems' => $a->current_version_id !== null
            ? Reviser::problems(ArticleVersion::query()->findOrFail($a->current_version_id))->count() : 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function audit(Article $a, ArticleVersion $v, StepContext $ctx, WorkflowRun $run): array
    {
        $this->moveTo($a, ArticleStatus::QualityReview);
        $r = $this->quality->audit($a, $v, $ctx->callKey(), $run->id);

        return ['report_id' => $r->id, 'score' => $r->score];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize(Article $a, WorkflowRun $run): array
    {
        $this->moveTo($a, ArticleStatus::NeedsHumanReview, ['last_error_code' => null, 'last_error_message' => null]);
        ArticleDraftReady::dispatch($a->workspace_id, $a->id, $a->current_version_id, self::imageTarget($a));
        app(AutoApprover::class)->attempt($a->refresh(), $run->id);
        app(Batches::class)->articleFinished($a->refresh());

        return ['status' => $a->status->value, 'unresolved_critical' => $a->unresolved_critical, 'unresolved_high' => $a->unresolved_high];
    }

    /**
     * Images to add automatically: the article's switch, else the preset's choice, else the platform default (0 = off).
     */
    public static function imageTarget(Article $a): int
    {
        $defaults = $a->writing_preset_id !== null ? (WritingPreset::query()->find($a->writing_preset_id)->defaults ?? []) : [];
        $own = $a->setting('auto_images');
        $enabled = match (true) {
            is_bool($own) => $own,
            array_key_exists('auto_images', $defaults) && $defaults['auto_images'] !== null => (bool) $defaults['auto_images'],
            default => (bool) config('sitex.media.auto_images', true),
        };
        $min = isset($defaults['min_images']) ? (int) $defaults['min_images'] : (int) config('sitex.media.min_article_images', 2);

        return $enabled ? max(0, min(6, $min)) : 0;
    }
}
