<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\ArticleWorkflows;
use SiteXPost\Modules\Writer\Actions\Batches;
use SiteXPost\Modules\Writer\Actions\CreateArticle;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleAiUsage;
use SiteXPost\Modules\Writer\Support\WriterQuota;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Writer dashboard data: quota and cost this month, status counts, the
 * wizard's options (content types, limits, approved Phase 4 ideas) and a
 * pre-creation cost estimate.
 */
final class WriterWorkspaceController
{
    public function overview(Request $request, Workspace $workspace, WriterQuota $quota, ArticleWorkflows $workflows): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $counts = Article::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $month = now()->startOfMonth();
        $cost = (int) ArticleAiUsage::query()->where('created_at', '>=', $month)->sum('cost_micro_usd');

        return new JsonResponse(['data' => [
            'quota' => $quota->status(),
            'cost_this_month_micro_usd' => $cost,
            'active_workflows' => $workflows->activeInWorkspace(),
            'status_counts' => $counts,
            'needs_review' => $counts['NEEDS_HUMAN_REVIEW'] ?? 0,
        ]]);
    }

    public function options(Request $request, Workspace $workspace, WriterSettings $settings): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $ws = $workspace->id;

        return new JsonResponse(['data' => [
            'content_types' => CreateArticle::CONTENT_TYPES,
            'review_modes' => ['step_by_step', 'brief_only', 'final_only'],
            'depths' => ['basic', 'standard', 'deep'],
            'limits' => [
                'default_article_budget_micro_usd' => $settings->int('default_article_budget_micro_usd', $ws),
                'max_article_budget_micro_usd' => $settings->int('max_article_budget_micro_usd', $ws),
                'max_batch_size' => $settings->int('max_batch_size', $ws),
                'max_batch_concurrency' => $settings->int('max_batch_concurrency', $ws),
                'max_sources' => ['basic' => $settings->maxSources('basic', $ws), 'standard' => $settings->maxSources('standard', $ws), 'deep' => $settings->maxSources('deep', $ws)],
                'min_quality_score_for_approval' => $settings->int('min_quality_score_for_approval', $ws),
            ],
        ]]);
    }

    public function ideas(Request $request, Workspace $workspace, PlannerDirectory $planner): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $campaign = $request->query('campaign_id');
        if (! is_string($campaign) || preg_match('/^[0-9a-z]{26}$/i', $campaign) !== 1) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['campaign_id' => [__('writer.errors.campaign_not_found')]]);
        }
        if ($planner->campaign($campaign) === null) {
            throw ApiException::notFound();
        }
        $ideas = $planner->approvedIdeas($campaign, null, 200);
        $taken = Article::query()->whereIn('content_idea_id', array_column($ideas, 'id'))->where('status', '!=', 'CANCELLED')->pluck('id', 'content_idea_id');

        return new JsonResponse(['data' => array_map(fn ($i) => $i + ['article_id' => $taken[$i['id']] ?? null], $ideas)]);
    }

    public function estimate(Request $request, Workspace $workspace, Batches $batches): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $words = max(300, min(6000, (int) $request->integer('target_words', 1500)));

        return new JsonResponse(['data' => ['target_words' => $words, 'estimated_cost_micro_usd' => $batches->estimatePerArticle($words),
            'note' => __('writer.estimate_note')]]);
    }
}
