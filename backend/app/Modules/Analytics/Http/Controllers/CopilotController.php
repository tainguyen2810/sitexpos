<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Actions\Copilot;
use SiteXPost\Modules\Analytics\Domain\CopilotQuery;
use SiteXPost\Modules\Analytics\Http\AnalyticsPresenter;
use SiteXPost\Modules\Analytics\Http\Requests\CopilotRequest;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * SEO Copilot: answers only from this workspace's stored data, separates
 * facts / inferences / recommendations and never executes anything.
 */
final class CopilotController
{
    public function ask(CopilotRequest $request, Workspace $workspace, Copilot $copilot, DashboardQuery $dash, ArticleCatalog $articles): JsonResponse
    {
        Gate::authorize('viewAny', CopilotQuery::class);
        $d = $request->validated();
        $siteId = ! empty($d['site_id']) ? $dash->site((string) $d['site_id']) : null;
        $articleId = $d['article_id'] ?? null;
        if ($articleId !== null && $articles->find((string) $articleId) === null) {
            throw ApiException::notFound();
        }
        $row = $copilot->ask((string) $d['question'], $siteId, (string) $request->user()?->getAuthIdentifier(), $articleId);
        $row->refresh();

        return new JsonResponse(['data' => AnalyticsPresenter::copilot($row)], $row->mode === 'ai_pending' ? 202 : 201);
    }

    public function show(Request $request, Workspace $workspace, CopilotQuery $copilotQuery): JsonResponse
    {
        Gate::authorize('view', $copilotQuery);
        if ($copilotQuery->user_id !== (string) $request->user()?->getAuthIdentifier()) {
            throw ApiException::notFound();
        }

        return new JsonResponse(['data' => AnalyticsPresenter::copilot($copilotQuery)]);
    }

    public function history(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', CopilotQuery::class);
        $rows = CopilotQuery::query()->where('user_id', (string) $request->user()?->getAuthIdentifier())->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();

        return new JsonResponse(['data' => $rows->map(fn ($q) => AnalyticsPresenter::copilot($q))->all()]);
    }
}
