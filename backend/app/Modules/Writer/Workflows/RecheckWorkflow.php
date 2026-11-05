<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Workflows;

use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Pipeline\AutoApprover;
use SiteXPost\Modules\Writer\Pipeline\ClaimExtractor;
use SiteXPost\Modules\Writer\Pipeline\FactChecker;
use SiteXPost\Modules\Writer\Pipeline\QualityAuditor;
use SiteXPost\Modules\Writer\Pipeline\Reviser;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Re-check after human edits: extract claims of sections not checked since
 * their last change (or every section when `full`), fact-check them,
 * optional bounded auto-fix, quality audit, back to human review.
 */
final class RecheckWorkflow extends ArticleWorkflow
{
    public const TYPE = 'writer.recheck';

    protected const STEPS = ['prepare' => 5, 'claims' => 30, 'factcheck' => 60, 'revise' => 80, 'quality' => 95, 'finalize' => 99];

    protected const PAID = ['claims', 'factcheck', 'revise', 'quality'];

    public function __construct(
        ArticleStateMachine $machine,
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
        return 'factcheck';
    }

    protected function step(string $step, Article $a, WorkflowRun $run, StepContext $ctx): array
    {
        $version = fn (): ArticleVersion => ArticleVersion::query()->findOrFail((string) $a->refresh()->current_version_id);
        $full = (bool) ($run->input['full'] ?? false);

        return match ($step) {
            'prepare' => $this->prepare($a, $full),
            'claims' => $this->claims->extract($a, $version(), $full ? array_column($version()->section_hashes ?? [], 'key') : null, $ctx->callKey(), $run->id),
            'factcheck' => ['run_id' => $this->checker->check($a, $version(), 0, $ctx->callKey(), $run->id)->id],
            'revise' => (bool) ($run->input['auto_revise'] ?? false)
                ? DraftWorkflow::reviseLoop($a, $ctx, $run, $this->reviser, $this->claims, $this->checker, $this->machine, $this->settings)
                : ['skipped' => 'auto_revise_off'],
            'quality' => $this->audit($a, $version(), $ctx, $run),
            'finalize' => $this->finalize($a, $run->id),
            default => throw new StepFailed('unknown_step', $step),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(Article $a, bool $full): array
    {
        if ($a->current_version_id === null) {
            throw new StepFailed('no_content', __('writer.errors.no_content'));
        }
        if ($full) {
            // Full re-check: every claim of the current version is re-verified.
            ArticleClaim::query()->where('article_version_id', $a->current_version_id)->update(['status' => 'PENDING']);
        }
        $this->moveTo($a, ArticleStatus::FactChecking);

        return ['version_id' => $a->current_version_id, 'full' => $full];
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
    private function finalize(Article $a, ?string $runId = null): array
    {
        $this->moveTo($a, ArticleStatus::NeedsHumanReview, ['last_error_code' => null, 'last_error_message' => null]);
        app(AutoApprover::class)->attempt($a->refresh(), $runId);

        return ['status' => $a->status->value];
    }
}
