<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Actions\TrackExperiments;
use SiteXPost\Modules\Analytics\Domain\ContentDecayEvent;
use SiteXPost\Modules\Analytics\Domain\ExperimentObservation;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Engine\OpportunityRules;
use SiteXPost\Modules\Analytics\Engine\RecommendationEngine;
use SiteXPost\Modules\Analytics\Http\AnalyticsPresenter;
use SiteXPost\Modules\Analytics\Http\Requests\CreateExperimentRequest;
use SiteXPost\Modules\Analytics\Http\Requests\DecideDecayRequest;
use SiteXPost\Modules\Analytics\Http\Requests\DecideOpportunityRequest;
use SiteXPost\Modules\Analytics\Http\Requests\DecideRecommendationRequest;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Opportunities, content decay, recommendations and before/after
 * experiments. Every decision is a human action recorded in the audit log.
 */
final class InsightController
{
    public function __construct(private readonly DashboardQuery $dash) {}

    public function opportunities(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', SeoOpportunity::class);
        $q = $this->filtered(SeoOpportunity::query(), $request, ['status' => ['open', 'accepted', 'dismissed', 'resolved', 'expired'],
            'severity' => ['low', 'medium', 'high', 'critical'], 'rule_code' => OpportunityRules::RULES]);
        $rows = $q->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('confidence')->orderByDesc('last_detected_at')->orderBy('id')->limit(300)->get();

        return new JsonResponse(['data' => $rows->map(fn (SeoOpportunity $o) => AnalyticsPresenter::opportunity($o))->all(),
            'meta' => ['rules' => OpportunityRules::RULES, 'rule_version' => OpportunityRules::VERSION]]);
    }

    public function opportunity(Workspace $workspace, SeoOpportunity $opportunity): JsonResponse
    {
        Gate::authorize('view', $opportunity);

        return new JsonResponse(['data' => AnalyticsPresenter::opportunity($opportunity)
            + ['recommendations' => SeoRecommendation::query()->where('opportunity_id', $opportunity->id)->get()->map(fn ($r) => AnalyticsPresenter::recommendation($r))->all()]]);
    }

    public function decideOpportunity(DecideOpportunityRequest $request, Workspace $workspace, SeoOpportunity $opportunity): JsonResponse
    {
        Gate::authorize('operate', $opportunity);
        $status = (string) $request->validated('status');
        $opportunity->forceFill(['status' => $status, 'reviewed_by' => (string) $request->user()?->getAuthIdentifier(), 'reviewed_at' => now(),
            'review_note' => $request->validated('note')])->save();
        if ($status === 'dismissed') {
            SeoRecommendation::query()->where('opportunity_id', $opportunity->id)->where('status', 'proposed')->update(['status' => 'dismissed', 'decided_at' => now()]);
        }
        Audit::record('analytics.opportunity_reviewed', $opportunity, ['status' => $status, 'rule' => $opportunity->rule_code]);

        return new JsonResponse(['data' => AnalyticsPresenter::opportunity($opportunity)]);
    }

    public function decay(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', ContentDecayEvent::class);
        $q = $this->filtered(ContentDecayEvent::query(), $request, ['status' => ['DECAY_SUSPECTED', 'NEEDS_INVESTIGATION', 'CONFIRMED_ISSUE', 'NO_ACTION']]);
        if (! $request->filled('status')) {
            $q->whereNotNull('open_key');
        }

        return new JsonResponse(['data' => $q->orderBy('decline_pct')->orderBy('id')->limit(300)->get()->map(fn ($d) => AnalyticsPresenter::decay($d))->all()]);
    }

    public function decideDecay(DecideDecayRequest $request, Workspace $workspace, ContentDecayEvent $decay): JsonResponse
    {
        Gate::authorize('operate', $decay);
        $status = (string) $request->validated('status');
        $decay->forceFill(['status' => $status, 'decided_by' => (string) $request->user()?->getAuthIdentifier(), 'decided_at' => now(),
            'decision_note' => $request->validated('note'), 'open_key' => $status === 'NO_ACTION' ? null : $decay->open_key])->save();
        Audit::record('analytics.decay_reviewed', $decay, ['status' => $status]);

        return new JsonResponse(['data' => AnalyticsPresenter::decay($decay)]);
    }

    public function recommendations(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', SeoRecommendation::class);
        $q = $this->filtered(SeoRecommendation::query(), $request, ['status' => ['proposed', 'approved', 'rejected', 'in_progress', 'applied', 'dismissed'],
            'type' => RecommendationEngine::TYPES, 'source' => ['rule', 'ai', 'agent']]);
        if (! $request->filled('status')) {
            $q->whereIn('status', ['proposed', 'approved', 'in_progress']);
        }

        return new JsonResponse(['data' => $q->orderByDesc('priority')->orderByDesc('created_at')->orderBy('id')->limit(300)->get()
            ->map(fn ($r) => AnalyticsPresenter::recommendation($r))->all(), 'meta' => ['refresh_types' => RecommendationEngine::REFRESH_TYPES]]);
    }

    public function decideRecommendation(DecideRecommendationRequest $request, Workspace $workspace, SeoRecommendation $recommendation): JsonResponse
    {
        Gate::authorize('operate', $recommendation);
        if (! in_array($recommendation->status, ['proposed', 'approved'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.recommendation_closed'));
        }
        $status = (string) $request->validated('status');
        $recommendation->forceFill(['status' => $status, 'decided_by' => (string) $request->user()?->getAuthIdentifier(), 'decided_at' => now(),
            'decision_note' => $request->validated('note')])->save();
        Audit::record('analytics.recommendation_decided', $recommendation, ['status' => $status, 'type' => $recommendation->type]);

        return new JsonResponse(['data' => AnalyticsPresenter::recommendation($recommendation)]);
    }

    public function experiments(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', SeoExperiment::class);
        $q = $this->filtered(SeoExperiment::query(), $request, ['status' => ['observing', 'completed', 'insufficient_data', 'cancelled']]);

        return new JsonResponse(['data' => $q->orderByDesc('change_date')->orderBy('id')->limit(200)->get()->map(fn ($e) => AnalyticsPresenter::experiment($e))->all(),
            'meta' => ['method_note' => __('analytics.labels.before_after_note')]]);
    }

    public function experiment(Workspace $workspace, SeoExperiment $experiment): JsonResponse
    {
        Gate::authorize('view', $experiment);

        return new JsonResponse(['data' => AnalyticsPresenter::experiment($experiment) + ['observations' => ExperimentObservation::query()->where('experiment_id', $experiment->id)->orderBy('date')->get(['date', 'phase', 'clicks', 'impressions', 'position'])
            ->map(fn ($o) => ['date' => $o->date, 'phase' => $o->phase, 'clicks' => $o->clicks, 'impressions' => $o->impressions, 'position' => $o->position !== null ? (float) $o->position : null])->all()]]);
    }

    public function storeExperiment(CreateExperimentRequest $request, Workspace $workspace, TrackExperiments $action, ArticleCatalog $articles): JsonResponse
    {
        Gate::authorize('create', SeoExperiment::class);
        $d = $request->validated();
        $siteId = $this->dash->site((string) $d['site_id']);
        if (! empty($d['article_id']) && (($a = $articles->find((string) $d['article_id'])) === null || $a['site_id'] !== $siteId)) {
            throw ApiException::notFound();
        }
        $e = $action->start($siteId, $d['article_id'] ?? null, (string) $d['page_url'], (string) $d['change_type'], null, Carbon::parse((string) $d['change_date']),
            (string) $request->user()?->getAuthIdentifier());
        if (! empty($d['note'])) {
            $e->forceFill(['external_factors' => mb_substr((string) $d['note'], 0, 1000)])->save();
        }

        return new JsonResponse(['data' => AnalyticsPresenter::experiment($e)], 201);
    }

    public function cancelExperiment(Request $request, Workspace $workspace, SeoExperiment $experiment): JsonResponse
    {
        Gate::authorize('operate', $experiment);
        if ($experiment->status !== 'observing') {
            throw ApiException::conflict('invalid_state_transition', __('analytics.errors.experiment_closed'));
        }
        $experiment->forceFill(['status' => 'cancelled'])->save();
        Audit::record('analytics.experiment_cancelled', $experiment);

        return new JsonResponse(['data' => AnalyticsPresenter::experiment($experiment)]);
    }

    /**
     * Whitelisted equality filters + the site filter (resolved in-tenant).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @param  array<string, list<string>>  $allowed
     * @return Builder<TModel>
     */
    private function filtered(Builder $q, Request $request, array $allowed): Builder
    {
        $site = $request->query('site_id');
        if (is_string($site) && $site !== '') {
            $q->where('site_id', $this->dash->site($site));
        }
        $article = $request->query('article_id');
        if (is_string($article) && preg_match('/^[0-9a-z]{26}$/i', $article) === 1) {
            $q->where('article_id', $article);
        }
        foreach ($allowed as $field => $values) {
            $v = $request->query($field);
            if (is_string($v) && in_array($v, $values, true)) {
                $q->where($field, $v);
            }
        }

        return $q;
    }
}
