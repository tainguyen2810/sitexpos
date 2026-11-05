<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Media\Actions\GenerateImages;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Http\MediaPresenter;
use SiteXPost\Modules\Media\Http\Requests\GenerateImageRequest;
use SiteXPost\Modules\Media\Http\Requests\RegenerateImageRequest;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Http\Idempotency;

/**
 * AI Image Generator: prompt preview (editable, with cost estimate) →
 * generate (202 + job_run) → history with versions and cost → regenerate.
 */
final class ImageGenerationController
{
    public function __construct(private readonly GenerateImages $generate) {}

    public function preview(GenerateImageRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);

        return new JsonResponse(['data' => $this->generate->preview($workspace->id, $request->validated())]);
    }

    public function store(GenerateImageRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);
        ['job' => $job, 'job_run' => $run] = $this->generate->create($workspace->id, $request->validated(), (string) $request->user()?->getAuthIdentifier(), Idempotency::key($request));

        return new JsonResponse(['data' => MediaPresenter::job($job->refresh()), 'job_run' => $run !== null ? ['id' => $run->id, 'status' => $run->refresh()->status] : null],
            $job->wasRecentlyCreated ? 202 : 200);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', MediaAsset::class);
        $q = ImageGenerationJob::query();
        foreach (['site_id', 'article_id', 'status'] as $f) {
            $v = $request->query($f);
            if (is_string($v) && $v !== '') {
                $q->where($f, $v);
            }
        }

        return MediaPresenter::page($q->orderByDesc('created_at')->orderByDesc('id'), fn (ImageGenerationJob $j) => MediaPresenter::job($j), $request, 20);
    }

    public function show(Workspace $workspace, ImageGenerationJob $imageJob): JsonResponse
    {
        Gate::authorize('view', $imageJob);

        return new JsonResponse(['data' => MediaPresenter::job($imageJob)]);
    }

    public function regenerate(RegenerateImageRequest $request, Workspace $workspace, ImageGenerationJob $imageJob): JsonResponse
    {
        Gate::authorize('operate', $imageJob);
        ['job' => $job, 'job_run' => $run] = $this->generate->regenerate($workspace->id, $imageJob, $request->validated('prompt'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => MediaPresenter::job($job->refresh()), 'job_run' => $run !== null ? ['id' => $run->id, 'status' => $run->refresh()->status] : null], 202);
    }
}
