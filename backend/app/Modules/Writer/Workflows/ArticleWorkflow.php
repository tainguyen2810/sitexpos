<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Workflows;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Workflows\Engine\WorkflowDefinition;
use SiteXPost\Modules\Writer\Ai\BudgetBlocked;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\InvalidArticleTransition;

/**
 * Base of the article workflows (Phase 3 durable engine): every step is
 * idempotent and checkpointed; paid steps are never blindly retried after
 * a worker crash (allowsPaidRetry = false). Budget problems stop the run
 * without failing the article: data is kept and the run can be resumed
 * once budget is available.
 */
abstract class ArticleWorkflow implements WorkflowDefinition
{
    /** @var array<string, int> step => progress % */
    protected const STEPS = [];

    /** @var list<string> */
    protected const PAID = [];

    public function __construct(protected readonly ArticleStateMachine $machine) {}

    public function steps(): array
    {
        return array_keys(static::STEPS);
    }

    public function maxAttempts(array $input): int
    {
        return 3;
    }

    public function isPaidStep(string $step): bool
    {
        return in_array($step, static::PAID, true);
    }

    public function allowsPaidRetry(WorkflowRun $run): bool
    {
        return false;
    }

    public function output(WorkflowRun $run, array $outputs): array
    {
        return ['article_id' => $run->input['article_id'] ?? null, 'steps' => array_keys($outputs)];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function step(string $step, Article $article, WorkflowRun $run, StepContext $context): array;

    public function runStep(string $step, WorkflowRun $run, StepContext $context): array
    {
        $article = Article::query()->find((string) ($run->input['article_id'] ?? ''));
        if ($article === null) {
            throw new StepFailed('article_missing', 'Article not found.');
        }
        if ($article->status === ArticleStatus::Cancelled) {
            throw new StepFailed('article_cancelled', __('writer.errors.article_cancelled'));
        }

        try {
            return $this->step($step, $article, $run, $context);
        } catch (BudgetBlocked $e) {
            $article->forceFill(['blocked_reason' => 'article_budget_exhausted', 'last_error_code' => 'article_budget_exhausted',
                'last_error_message' => __('writer.errors.budget_exhausted', ['needed' => round($e->needed / 1_000_000, 4), 'left' => round($e->remaining / 1_000_000, 4)])])->save();
            throw new StepFailed('article_budget_exhausted', $article->last_error_message ?? 'budget');
        } catch (AiGatewayException $e) {
            if ($e->errorCode === 'budget_exceeded') {
                $article->forceFill(['blocked_reason' => 'workspace_budget_exceeded', 'last_error_code' => 'workspace_budget_exceeded', 'last_error_message' => $e->getMessage()])->save();
                throw new StepFailed('workspace_budget_exceeded', $e->getMessage());
            }
            $article->forceFill(['last_error_code' => mb_substr($e->errorCode, 0, 64), 'last_error_message' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw new StepFailed($e->errorCode, $e->getMessage(), $e->retryable, $e->requiresPaidRetry);
        } catch (InvalidArticleTransition $e) {
            throw new StepFailed('invalid_article_state', $e->getMessage());
        }
    }

    /**
     * Move the article (refreshing it first); a concurrent change (user
     * cancelled, another run moved it) stops the step cleanly.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function moveTo(Article $article, ArticleStatus $to, array $attrs = []): void
    {
        if (! $this->machine->move($article, $to, $attrs + ['blocked_reason' => null])) {
            throw new StepFailed('invalid_article_state', "Article is {$article->status->value}, cannot move to {$to->value}.");
        }
    }

    public static function stepLabel(string $step): string
    {
        $key = 'writer.steps.'.$step;
        $t = __($key);

        return $t === $key ? $step : (string) $t;
    }
}
