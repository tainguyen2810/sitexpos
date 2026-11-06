<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Publishing\Actions\Batches;
use SiteXPost\Modules\Publishing\Domain\PublishingBatch;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Http\PublishingPresenter as P;
use SiteXPost\Modules\Publishing\Http\Requests\BatchRequest;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Bulk publishing (preview → create → pause / resume / cancel).
 * Creating a batch is an explicit approval of every listed item, so it
 * needs content.publish; every item still passes every gate.
 */
final class BatchController
{
    public function __construct(private readonly Batches $batches) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);

        return P::page(PublishingBatch::query()->orderByDesc('created_at')->orderByDesc('id'), fn (PublishingBatch $b) => P::batch($b), $request, 20);
    }

    public function preview(BatchRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('create', PublishingJob::class);
        $d = $request->validated();

        return new JsonResponse(['data' => $this->batches->preview((string) $d['site_id'], array_values($d['article_ids']), (string) $d['mode'], $d['start_at'] ?? null, (int) ($d['interval_minutes'] ?? 60))]);
    }

    public function store(BatchRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('publish', PublishingJob::class);
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (! is_string($key) || preg_match('/^[A-Za-z0-9_\-:.]{8,120}$/', $key) !== 1)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['Idempotency-Key' => ['8–120 characters: letters, digits, _ - : .']]);
        }
        $d = $request->validated();
        $b = $this->batches->create(['site_id' => (string) $d['site_id'], 'article_ids' => array_values($d['article_ids']), 'mode' => (string) $d['mode'],
            'start_at' => $d['start_at'] ?? null, 'interval_minutes' => $d['interval_minutes'] ?? null], (string) $request->user()?->getAuthIdentifier(), $key);

        return new JsonResponse(['data' => P::batch($b->refresh(), true)], $b->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Workspace $workspace, PublishingBatch $publishingBatch): JsonResponse
    {
        Gate::authorize('view', $publishingBatch);

        return new JsonResponse(['data' => P::batch($publishingBatch, true)]);
    }

    public function pause(Request $request, Workspace $workspace, PublishingBatch $publishingBatch): JsonResponse
    {
        Gate::authorize('publish', $publishingBatch);

        return new JsonResponse(['data' => P::batch($this->batches->pause($publishingBatch, (string) $request->user()?->getAuthIdentifier()), true)]);
    }

    public function resume(Request $request, Workspace $workspace, PublishingBatch $publishingBatch): JsonResponse
    {
        Gate::authorize('publish', $publishingBatch);

        return new JsonResponse(['data' => P::batch($this->batches->resume($publishingBatch, (string) $request->user()?->getAuthIdentifier()), true)]);
    }

    public function cancel(Request $request, Workspace $workspace, PublishingBatch $publishingBatch): JsonResponse
    {
        Gate::authorize('publish', $publishingBatch);

        return new JsonResponse(['data' => P::batch($this->batches->cancel($publishingBatch, (string) $request->user()?->getAuthIdentifier()), true)]);
    }
}
