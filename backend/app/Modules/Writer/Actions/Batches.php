<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Writer\Ai\AiCaller;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Domain\WritingBatchItem;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Support\WriterQuota;
use SiteXPost\Modules\Writer\Support\WriterSettings;
use Throwable;

/**
 * Bulk generation from approved Content Ideas: bounded size, quota
 * reserved up front, per-article budget cap, concurrency limit,
 * pause / resume / cancel, per-item status, retry of failed items,
 * duplicate prevention. Articles stop at NEEDS_HUMAN_REVIEW — nothing is
 * published in Phase 5.
 */
class Batches
{
    public function __construct(
        private readonly PlannerDirectory $planner,
        private readonly WriterSettings $settings,
        private readonly WriterQuota $quota,
        private readonly AiCaller $caller,
    ) {}

    /**
     * @param  list<string>  $ideaIds
     * @return array<string, mixed>
     */
    public function preview(string $campaignId, array $ideaIds, int $perArticleBudget, int $targetWords = 1500): array
    {
        $campaign = $this->planner->campaign($campaignId) ?? throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['campaign_id' => [__('writer.errors.campaign_not_found')]]);
        $ideas = $this->planner->approvedIdeas($campaignId, $ideaIds, 500);
        $taken = Article::query()->whereIn('content_idea_id', array_column($ideas, 'id'))->where('status', '!=', 'CANCELLED')->pluck('content_idea_id')->flip();
        $rows = [];
        foreach ($ideas as $i) {
            $rows[] = ['id' => $i['id'], 'title' => $i['title'], 'primary_keyword' => $i['primary_keyword'], 'duplicate' => $taken->has($i['id'])];
        }
        $notApproved = array_values(array_diff($ideaIds, array_column($ideas, 'id')));
        $creatable = count(array_filter($rows, fn ($r) => ! $r['duplicate']));
        $perArticle = $this->estimatePerArticle($targetWords);

        return [
            'campaign' => ['id' => $campaign['id'], 'name' => $campaign['name'], 'site_id' => $campaign['site_id']],
            'items' => $rows, 'not_approved_or_missing' => $notApproved, 'creatable' => $creatable,
            'estimated_cost_per_article_micro_usd' => $perArticle,
            'estimated_cost_micro_usd' => $perArticle * $creatable,
            'max_cost_micro_usd' => $perArticleBudget * $creatable,
            'quota' => $this->quota->status(),
            'limits' => ['max_batch_size' => $this->settings->int('max_batch_size', $this->ws()), 'max_concurrency' => $this->settings->int('max_batch_concurrency', $this->ws())],
        ];
    }

    /**
     * Rough upper estimate of one article (route prices × typical prompt sizes).
     */
    public function estimatePerArticle(int $targetWords): int
    {
        $sections = max(4, (int) ceil($targetWords / 250));
        $c = fn (string $task, int $chars, int $out): int => $this->caller->estimate($task, $chars, $out);

        return $c(WriterAi::TASK_QUESTIONS, 3000, 1500) + $c(WriterAi::TASK_DISCOVERY, 2000, 1500) + 8 * $c(WriterAi::TASK_SOURCE, 11000, 3000)
            + $c(WriterAi::TASK_BRIEF, 15000, 4000) + $c(WriterAi::TASK_OUTLINE, 12000, 4000) + $sections * $c(WriterAi::TASK_DRAFT, 12000, 1500)
            + $sections * $c(WriterAi::TASK_CLAIMS, 4000, 1500) + 3 * $c(WriterAi::TASK_FACTCHECK, 12000, 3000)
            + 2 * $c(WriterAi::TASK_REWRITE, 8000, 3000) + $c(WriterAi::TASK_SEO, 13000, 1000) + $c(WriterAi::TASK_FINAL, 13000, 1000);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId, ?string $key, string $source = 'manual'): WritingBatch
    {
        if ($key !== null && ($existing = WritingBatch::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }
        $ws = $this->ws();
        $ideaIds = array_values(array_unique($data['content_idea_ids']));
        $max = $this->settings->int('max_batch_size', $ws);
        if (count($ideaIds) > $max) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_idea_ids' => [__('writer.errors.batch_too_large', ['max' => $max])]]);
        }
        $concurrency = (int) ($data['concurrency'] ?? 2);
        if ($concurrency < 1 || $concurrency > $this->settings->int('max_batch_concurrency', $ws)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['concurrency' => [__('writer.errors.batch_concurrency', ['max' => $this->settings->int('max_batch_concurrency', $ws)])]]);
        }
        $budget = (int) ($data['per_article_budget_micro_usd'] ?? $this->settings->int('default_article_budget_micro_usd', $ws));
        if ($budget > $this->settings->int('max_article_budget_micro_usd', $ws)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['per_article_budget_micro_usd' => [__('writer.errors.budget_too_high', ['max' => $this->settings->int('max_article_budget_micro_usd', $ws) / 1_000_000])]]);
        }
        $preview = $this->preview((string) $data['campaign_id'], $ideaIds, $budget, (int) ($data['settings']['target_words'] ?? 1500));
        if ($preview['creatable'] === 0) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['content_idea_ids' => [__('writer.errors.batch_nothing_to_create')]]);
        }

        $this->quota->reserve($preview['creatable']);
        try {
            $batch = DB::transaction(function () use ($data, $preview, $userId, $key, $source, $concurrency, $budget): WritingBatch {
                $b = new WritingBatch;
                $b->forceFill([
                    'site_id' => $preview['campaign']['site_id'], 'campaign_id' => $preview['campaign']['id'], 'writing_preset_id' => $data['writing_preset_id'] ?? null,
                    'source' => $source, 'status' => 'running', 'total' => count($preview['items']), 'concurrency' => $concurrency,
                    'settings' => $data['settings'] ?? [], 'per_article_budget_micro_usd' => $budget,
                    'estimated_cost_micro_usd' => $preview['estimated_cost_micro_usd'], 'reserved_cost_micro_usd' => $preview['max_cost_micro_usd'],
                    'idempotency_key' => $key, 'created_by' => $userId,
                ])->save();
                foreach ($preview['items'] as $i => $idea) {
                    $item = new WritingBatchItem;
                    $item->forceFill(['writing_batch_id' => $b->id, 'content_idea_id' => $idea['id'], 'title' => mb_substr($idea['title'], 0, 300), 'position' => $i + 1,
                        'status' => $idea['duplicate'] ? 'skipped_duplicate' : 'queued'])->save();
                }

                return $b;
            });
        } catch (Throwable $e) {
            $this->quota->release($preview['creatable']);
            throw $e;
        }
        Audit::record('writer.batch_created', $batch, ['items' => $batch->total, 'creatable' => $preview['creatable'], 'reserved_micro_usd' => $batch->reserved_cost_micro_usd, 'source' => $source]);
        $this->advance($batch);

        return $batch->refresh();
    }

    /**
     * Start queued items up to the concurrency limit (idempotent, locked).
     */
    public function advance(WritingBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $b = WritingBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            if ($b === null || $b->status !== 'running') {
                return;
            }
            $running = WritingBatchItem::query()->where('writing_batch_id', $b->id)->where('status', 'running')->count();
            $slots = max(0, $b->concurrency - $running);
            $next = WritingBatchItem::query()->where('writing_batch_id', $b->id)->where('status', 'queued')->orderBy('position')->limit($slots)->get();
            foreach ($next as $item) {
                $this->startItem($b, $item);
            }
            $open = WritingBatchItem::query()->where('writing_batch_id', $b->id)->whereIn('status', ['queued', 'running'])->exists();
            if (! $open) {
                $b->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
            }
        });
    }

    private function startItem(WritingBatch $b, WritingBatchItem $item): void
    {
        $item->forceFill(['status' => 'running', 'attempts' => $item->attempts + 1, 'error_code' => null, 'error_message' => null])->save();
        try {
            $article = $item->article_id !== null ? Article::query()->find($item->article_id) : null;
            if ($article === null) {
                $article = app(CreateArticle::class)->handle([
                    'site_id' => $b->site_id, 'campaign_id' => $b->campaign_id, 'content_idea_id' => $item->content_idea_id, 'writing_preset_id' => $b->writing_preset_id,
                    'mode' => 'autonomous', 'review_mode' => 'final_only', 'budget_limit_micro_usd' => $b->per_article_budget_micro_usd,
                ] + array_intersect_key($b->settings ?? [], array_flip(['tone', 'target_words', 'research_depth', 'content_depth', 'language', 'country', 'cta', 'brand_rules', 'audience'])),
                    $b->created_by, 'batch:'.$item->id, reserveQuota: false, batchId: $b->id);
                $item->forceFill(['article_id' => $article->id])->save();
            } else {
                app(ArticleWorkflows::class)->resume($article, $b->created_by);
            }
        } catch (Throwable $e) {
            report($e);
            $item->forceFill(['status' => 'failed', 'error_code' => $e instanceof ApiException ? $e->errorCode : 'start_failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    /**
     * Called when an article of a batch reaches review or fails.
     */
    public function articleFinished(Article $article): void
    {
        if ($article->writing_batch_id === null) {
            return;
        }
        $item = WritingBatchItem::query()->where('writing_batch_id', $article->writing_batch_id)->where('article_id', $article->id)->first();
        if ($item === null || $item->status !== 'running') {
            return;
        }
        $ok = in_array($article->status, [ArticleStatus::NeedsHumanReview, ArticleStatus::Approved], true);
        $item->forceFill(['status' => $ok ? 'succeeded' : ($article->status === ArticleStatus::Cancelled ? 'canceled' : 'failed'),
            'error_code' => $ok ? null : $article->last_error_code])->save();
        $batch = WritingBatch::query()->find($article->writing_batch_id);
        if ($batch !== null) {
            $this->advance($batch);
        }
    }

    public function pause(WritingBatch $b): void
    {
        if ($b->status === 'running') {
            $b->forceFill(['status' => 'paused', 'paused_at' => now()])->save();
            Audit::record('writer.batch_paused', $b);
        }
    }

    public function resume(WritingBatch $b): void
    {
        if ($b->status === 'paused') {
            $b->forceFill(['status' => 'running', 'paused_at' => null])->save();
            Audit::record('writer.batch_resumed', $b);
            $this->advance($b);
        }
    }

    public function cancel(WritingBatch $b, ?string $userId): void
    {
        if (! in_array($b->status, ['running', 'paused'], true)) {
            return;
        }
        $queued = WritingBatchItem::query()->where('writing_batch_id', $b->id)->where('status', 'queued')->count();
        WritingBatchItem::query()->where('writing_batch_id', $b->id)->where('status', 'queued')->update(['status' => 'canceled', 'updated_at' => now()]);
        foreach (WritingBatchItem::query()->where('writing_batch_id', $b->id)->where('status', 'running')->whereNotNull('article_id')->get() as $item) {
            $article = Article::query()->find($item->article_id);
            if ($article !== null) {
                app(ArticleWorkflows::class)->cancel($article, $userId);
            }
            $item->forceFill(['status' => 'canceled'])->save();
        }
        $this->quota->release($queued); // never-started items give their quota back
        $b->forceFill(['status' => 'canceled', 'finished_at' => now()])->save();
        Audit::record('writer.batch_canceled', $b, ['released' => $queued]);
    }

    public function retryFailed(WritingBatch $b): int
    {
        $n = WritingBatchItem::query()->where('writing_batch_id', $b->id)->where('status', 'failed')->update(['status' => 'queued', 'updated_at' => now()]);
        if ($n > 0) {
            $b->forceFill(['status' => 'running', 'finished_at' => null, 'paused_at' => null])->save();
            Audit::record('writer.batch_retry_failed', $b, ['items' => $n]);
            $this->advance($b);
        }

        return $n;
    }

    private function ws(): string
    {
        return app(TenantContext::class)->workspaceId();
    }
}
