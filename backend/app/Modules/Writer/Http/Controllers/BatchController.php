<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\Batches;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Http\Idempotency;
use SiteXPost\Modules\Writer\Http\Requests\BatchRequest;
use SiteXPost\Modules\Writer\Http\WriterPresenter;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Bulk Generation: preview (cost, quota, duplicates) → create → monitor,
 * pause / resume / cancel / retry failed. Batch articles stop at human
 * review; nothing is published.
 */
final class BatchController
{
    public function __construct(private readonly Batches $batches) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);

        $q = WritingBatch::query();
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['queued', 'running', 'paused', 'completed', 'canceled', 'failed'], true)) {
            $q->where('status', $status);
        }

        return WriterPresenter::page($q->orderByDesc('created_at')->orderByDesc('id'), fn (WritingBatch $b) => WriterPresenter::batch($b), $request, 20);
    }

    public function preview(BatchRequest $request, Workspace $workspace, WriterSettings $settings): JsonResponse
    {
        Gate::authorize('create', Article::class);
        $data = $request->validated();
        $budget = (int) ($data['per_article_budget_micro_usd'] ?? $settings->int('default_article_budget_micro_usd', $workspace->id));

        return new JsonResponse(['data' => $this->batches->preview((string) $data['campaign_id'], array_values(array_unique($data['content_idea_ids'])), $budget,
            (int) ($data['settings']['target_words'] ?? 1500))]);
    }

    public function store(BatchRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('create', Article::class);
        $b = $this->batches->create($request->validated(), (string) $request->user()?->getAuthIdentifier(), Idempotency::key($request));

        return new JsonResponse(['data' => WriterPresenter::batch($b, true)], $b->wasRecentlyCreated ? 202 : 200);
    }

    public function show(Workspace $workspace, WritingBatch $batch): JsonResponse
    {
        Gate::authorize('view', $batch);

        return new JsonResponse(['data' => WriterPresenter::batch($batch, true)]);
    }

    public function pause(Workspace $workspace, WritingBatch $batch): JsonResponse
    {
        Gate::authorize('operate', $batch);
        $this->batches->pause($batch);

        return new JsonResponse(['data' => WriterPresenter::batch($batch->refresh(), true)]);
    }

    public function resume(Workspace $workspace, WritingBatch $batch): JsonResponse
    {
        Gate::authorize('operate', $batch);
        $this->batches->resume($batch);

        return new JsonResponse(['data' => WriterPresenter::batch($batch->refresh(), true)]);
    }

    public function cancel(Request $request, Workspace $workspace, WritingBatch $batch): JsonResponse
    {
        Gate::authorize('operate', $batch);
        $this->batches->cancel($batch, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => WriterPresenter::batch($batch->refresh(), true)]);
    }

    public function retry(Workspace $workspace, WritingBatch $batch): JsonResponse
    {
        Gate::authorize('operate', $batch);
        $n = $this->batches->retryFailed($batch);

        return new JsonResponse(['data' => WriterPresenter::batch($batch->refresh(), true) + ['retried' => $n]]);
    }
}
