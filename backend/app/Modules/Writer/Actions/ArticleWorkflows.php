<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\IdempotencyConflict;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleWorkflowRun;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Support\ArticleStateMachine;
use SiteXPost\Modules\Writer\Support\WriterSettings;
use SiteXPost\Modules\Writer\Workflows\DraftWorkflow;
use SiteXPost\Modules\Writer\Workflows\OutlineWorkflow;
use SiteXPost\Modules\Writer\Workflows\RecheckWorkflow;
use SiteXPost\Modules\Writer\Workflows\ResearchWorkflow;
use SiteXPost\Modules\Writer\Workflows\RewriteWorkflow;

/**
 * Start / resume / cancel the article workflows and keep the article
 * status in sync with the engine (a run that the engine failed or that was
 * cancelled is reflected on the article).
 */
class ArticleWorkflows
{
    public const KINDS = [
        'research' => ResearchWorkflow::TYPE,
        'outline' => OutlineWorkflow::TYPE,
        'draft' => DraftWorkflow::TYPE,
        'recheck' => RecheckWorkflow::TYPE,
        'rewrite' => RewriteWorkflow::TYPE,
    ];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WriterSettings $settings,
        private readonly ArticleStateMachine $machine,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws ApiException
     */
    public function start(Article $article, string $kind, ?string $userId, ?string $idempotencyKey = null, array $extra = [], bool $chained = false): WorkflowRun
    {
        $type = self::KINDS[$kind] ?? throw new \InvalidArgumentException($kind);
        $key = $idempotencyKey !== null ? 'writer:'.$article->id.':'.$kind.':'.$idempotencyKey : null;

        return DB::transaction(function () use ($article, $kind, $type, $userId, $key, $extra, $chained): WorkflowRun {
            Article::query()->whereKey($article->id)->lockForUpdate()->first();
            if ($key !== null && ($existing = WorkflowRun::query()->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }
            // A chained start comes from the hand-off step of the previous stage,
            // which (like any earlier stage still finishing its hand-off) is "running".
            $blocking = $kind === 'rewrite' ? collect() : $this->activeRuns($article, ['rewrite'])
                ->reject(fn (WorkflowRun $r) => $chained && $r->current_step === 'handoff');
            if ($blocking->isNotEmpty()) {
                throw ApiException::conflict('workflow_active', __('writer.errors.workflow_active'));
            }
            if ($article->status === ArticleStatus::Cancelled) {
                throw ApiException::conflict('article_cancelled', __('writer.errors.article_cancelled'));
            }
            if ($article->remainingBudget() <= 0) {
                throw ApiException::conflict('article_budget_exhausted', __('writer.errors.budget_exhausted_short'));
            }
            if (! $chained && $kind !== 'rewrite') {
                $limit = $this->settings->int('max_concurrent_workflows', $article->workspace_id);
                if ($this->activeInWorkspace() >= $limit) {
                    throw new ApiException(429, 'writer_concurrency_limit', __('writer.errors.concurrency', ['n' => $limit]));
                }
            }
            try {
                [$run] = $this->engine->start($type, ['article_id' => $article->id] + $extra, $key, $userId);
            } catch (IdempotencyConflict) {
                throw ApiException::conflict('idempotency_conflict', __('writer.errors.idempotency_conflict'));
            }
            $link = ArticleWorkflowRun::query()->where('workflow_run_id', $run->id)->first() ?? new ArticleWorkflowRun;
            $link->forceFill(['workflow_run_id' => $run->id, 'article_id' => $article->id, 'kind' => $kind, 'created_by' => $userId])->save();
            $article->forceFill(['blocked_reason' => null])->save();

            return $run;
        });
    }

    /**
     * @param  list<string>  $ignoreKinds
     */
    public function active(Article $article, array $ignoreKinds = []): ?WorkflowRun
    {
        $ids = ArticleWorkflowRun::query()->where('article_id', $article->id)->whereNotIn('kind', $ignoreKinds)->pluck('workflow_run_id');

        return $ids->isEmpty() ? null : WorkflowRun::query()->whereIn('id', $ids)
            ->whereIn('status', [WorkflowStatus::Pending->value, WorkflowStatus::Running->value, WorkflowStatus::RetryScheduled->value])->first();
    }

    /**
     * @param  list<string>  $ignoreKinds
     * @return Collection<int, WorkflowRun>
     */
    public function activeRuns(Article $article, array $ignoreKinds = []): Collection
    {
        $ids = ArticleWorkflowRun::query()->where('article_id', $article->id)->whereNotIn('kind', $ignoreKinds)->pluck('workflow_run_id');

        return $ids->isEmpty() ? collect() : WorkflowRun::query()->whereIn('id', $ids)
            ->whereIn('status', [WorkflowStatus::Pending->value, WorkflowStatus::Running->value, WorkflowStatus::RetryScheduled->value])->get();
    }

    public function activeInWorkspace(): int
    {
        return WorkflowRun::query()->whereIn('type', [ResearchWorkflow::TYPE, OutlineWorkflow::TYPE, DraftWorkflow::TYPE, RecheckWorkflow::TYPE])
            ->whereIn('status', [WorkflowStatus::Pending->value, WorkflowStatus::Running->value, WorkflowStatus::RetryScheduled->value])->count();
    }

    public function latest(Article $article): ?ArticleWorkflowRun
    {
        return ArticleWorkflowRun::query()->where('article_id', $article->id)->where('kind', '!=', 'rewrite')->latest()->orderByDesc('id')->first();
    }

    /**
     * Resume after a failure / budget stop / cancellation. Completed work is
     * never redone: the engine keeps succeeded steps and every step skips
     * what is already stored (sections, sources, evidence, claims).
     */
    public function resume(Article $article, ?string $userId): WorkflowRun
    {
        $link = $this->latest($article) ?? throw ApiException::conflict('nothing_to_resume', __('writer.errors.nothing_to_resume'));
        $run = WorkflowRun::query()->findOrFail($link->workflow_run_id);
        if ($run->status->isActive()) {
            throw ApiException::conflict('workflow_active', __('writer.errors.workflow_active'));
        }
        if ($article->remainingBudget() <= 0) {
            throw ApiException::conflict('article_budget_exhausted', __('writer.errors.budget_exhausted_short'));
        }
        if ($article->status === ArticleStatus::Failed && $article->status_before_failure !== null) {
            $this->machine->transition($article, ArticleStatus::from($article->status_before_failure), ['last_error_code' => null, 'last_error_message' => null]);
        }
        $article->forceFill(['blocked_reason' => null])->save();
        Audit::record('writer.workflow_resumed', $article, ['kind' => $link->kind]);
        if ($run->status === WorkflowStatus::Failed && $this->engine->retry($run, $userId)) {
            return $run->refresh();
        }

        return $this->start($article->refresh(), $link->kind, $userId, null, array_diff_key($run->input ?? [], ['article_id' => true]));
    }

    public function cancel(Article $article, ?string $userId): bool
    {
        $run = $this->active($article);
        if ($run === null) {
            return false;
        }
        $this->engine->cancel($run, $userId);
        Audit::record('writer.workflow_cancelled', $article, ['workflow_run_id' => $run->id]);

        return true;
    }

    /**
     * Reflect a failed / cancelled engine run on the article (the engine
     * does not call back into definitions on terminal failure).
     */
    public function reconcile(Article $article): Article
    {
        if (! $article->status->isBusy() && $article->status !== ArticleStatus::Outlining) {
            return $article;
        }
        if ($this->active($article, ['rewrite']) !== null) {
            return $article;
        }
        $link = $this->latest($article);
        $run = $link !== null ? WorkflowRun::query()->find($link->workflow_run_id) : null;
        if ($run === null || ! in_array($run->status, [WorkflowStatus::Failed, WorkflowStatus::Canceled], true)) {
            return $article;
        }
        if ($article->status === ArticleStatus::Outlining && $run->type === OutlineWorkflow::TYPE && $run->status === WorkflowStatus::Failed && $article->blocked_reason !== null) {
            return $article;
        }
        if ($article->blocked_reason !== null) {
            return $article; // budget stop: keep status, show the reason, allow resume
        }
        $code = $run->status === WorkflowStatus::Canceled ? 'workflow_cancelled' : ($run->error_code ?? 'workflow_failed');
        $this->machine->move($article, ArticleStatus::Failed, ['last_error_code' => mb_substr($code, 0, 64), 'last_error_message' => mb_substr((string) ($run->error_message ?? ''), 0, 1000) ?: null]);
        app(Batches::class)->articleFinished($article->refresh());

        return $article;
    }
}
