<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Actions\ContentRefresh;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Http\AnalyticsPresenter;
use SiteXPost\Modules\Analytics\Http\Requests\CreateRefreshRequest;
use SiteXPost\Modules\Analytics\Http\Requests\NoteRequest;
use SiteXPost\Modules\Analytics\Jobs\AdvanceRefreshJob;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Content refresh pipeline. Steps that call WordPress or the AI run in the
 * queue (202); approving content and approving the WordPress update are
 * two separate human decisions (content.publish).
 */
final class RefreshController
{
    public function index(Request $request, Workspace $workspace, DashboardQuery $dash): JsonResponse
    {
        Gate::authorize('viewAny', ContentRefreshJob::class);
        $q = ContentRefreshJob::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200);
        $site = $request->query('site_id');
        if (is_string($site) && $site !== '') {
            $q->where('site_id', $dash->site($site));
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, array_merge(ContentRefresh::ACTIVE, ContentRefresh::TERMINAL), true)) {
            $q->where('status', $status);
        }

        return new JsonResponse(['data' => $q->get()->map(fn ($j) => AnalyticsPresenter::refresh($j))->all()]);
    }

    public function store(CreateRefreshRequest $request, Workspace $workspace, ContentRefresh $action): JsonResponse
    {
        Gate::authorize('create', ContentRefreshJob::class);
        $d = $request->validated();
        $job = $action->create((string) $d['article_id'], $d['recommendation_id'] ?? null, (string) $request->user()?->getAuthIdentifier(), null, $d['idempotency_key'] ?? null);

        return new JsonResponse(['data' => AnalyticsPresenter::refresh($job->refresh(), true)], 202);
    }

    public function show(Workspace $workspace, ContentRefreshJob $refreshJob): JsonResponse
    {
        Gate::authorize('view', $refreshJob);

        return new JsonResponse(['data' => AnalyticsPresenter::refresh($refreshJob, true)]);
    }

    public function approve(NoteRequest $request, Workspace $workspace, ContentRefreshJob $refreshJob): JsonResponse
    {
        Gate::authorize('approve', $refreshJob);
        if ($refreshJob->status !== 'awaiting_approval' || $refreshJob->new_version_id === null) {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.refresh_not_awaiting'));
        }
        // Re-checks WordPress, approves the version in the Writer, runs the SEO audit, prepares the update job.
        AdvanceRefreshJob::dispatch($refreshJob->id, (string) $request->user()?->getAuthIdentifier(), $request->validated('note'));

        return new JsonResponse(['data' => AnalyticsPresenter::refresh($refreshJob->refresh(), true)], 202);
    }

    public function publish(Request $request, Workspace $workspace, ContentRefreshJob $refreshJob, ContentRefresh $action): JsonResponse
    {
        Gate::authorize('approve', $refreshJob);

        return new JsonResponse(['data' => AnalyticsPresenter::refresh($action->publish($refreshJob, (string) $request->user()?->getAuthIdentifier()), true)]);
    }

    public function cancel(Request $request, Workspace $workspace, ContentRefreshJob $refreshJob, ContentRefresh $action): JsonResponse
    {
        Gate::authorize('operate', $refreshJob);

        return new JsonResponse(['data' => AnalyticsPresenter::refresh($action->cancel($refreshJob, (string) $request->user()?->getAuthIdentifier()), true)]);
    }

    public function retry(Request $request, Workspace $workspace, ContentRefreshJob $refreshJob): JsonResponse
    {
        Gate::authorize('operate', $refreshJob);
        if (! in_array($refreshJob->status, ['drafting', 'fact_checking', 'publishing'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.refresh_not_waiting'));
        }
        AdvanceRefreshJob::dispatch($refreshJob->id);
        Audit::record('analytics.refresh_poll', $refreshJob);

        return new JsonResponse(['data' => AnalyticsPresenter::refresh($refreshJob->refresh(), true)], 202);
    }
}
