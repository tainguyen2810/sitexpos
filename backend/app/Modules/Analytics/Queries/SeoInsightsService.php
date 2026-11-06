<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Queries;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Actions\AnalyzeSite;
use SiteXPost\Modules\Analytics\Actions\ContentRefresh;
use SiteXPost\Modules\Analytics\Contracts\SeoInsights;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\ContentDecayEvent;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Engine\RecommendationEngine;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;

final class SeoInsightsService implements SeoInsights
{
    public function __construct(private readonly AnalyzeSite $analyze, private readonly ContentRefresh $refresh) {}

    public function analyze(string $siteId): array
    {
        return $this->analyze->run($siteId);
    }

    public function observations(string $siteId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));

        return [
            'data_latest' => ($d = AnalyticsProperty::query()->where('site_id', $siteId)->where('provider', 'gsc')->value('last_complete_date')) !== null ? (string) $d : null,
            'health' => ($h = SeoHealthScore::query()->where('site_id', $siteId)->orderByDesc('date')->first()) !== null ? $h->score : null,
            'open_opportunities' => SeoOpportunity::query()->where('site_id', $siteId)->where('status', 'open')->count(),
            'decay_open' => ContentDecayEvent::query()->where('site_id', $siteId)->whereNotNull('open_key')->count(),
            'opportunities' => SeoOpportunity::query()->where('site_id', $siteId)->where('status', 'open')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->orderByDesc('confidence')->orderBy('id')
                ->limit($limit)->get()->map(fn (SeoOpportunity $o): array => [
                    'id' => $o->id, 'rule' => $o->rule_code, 'severity' => $o->severity, 'impact' => $o->impact, 'confidence' => $o->confidence,
                    'article_id' => $o->article_id, 'target_label' => $o->target_label, 'recommended_action' => $o->recommended_action,
                    'estimated_ai_cost_micro_usd' => $o->estimated_ai_cost_micro_usd,
                ])->all(),
            'recommendations' => SeoRecommendation::query()->where('site_id', $siteId)->whereIn('status', ['proposed', 'approved'])
                ->orderByDesc('priority')->orderBy('id')->limit($limit)->get()->map(fn (SeoRecommendation $r): array => [
                    'id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'article_id' => $r->article_id, 'priority' => $r->priority,
                    'opportunity_id' => $r->opportunity_id, 'estimated_cost_micro_usd' => $r->estimated_cost_micro_usd, 'target_label' => $r->target_label,
                    'refresh' => in_array($r->type, RecommendationEngine::REFRESH_TYPES, true),
                ])->all(),
            'awaiting_publish' => ContentRefreshJob::query()->where('site_id', $siteId)->where('status', 'awaiting_publish')->whereNotNull('approved_by')
                ->limit($limit)->get()->map(fn (ContentRefreshJob $j): array => ['id' => $j->id, 'article_id' => $j->article_id, 'approved_by' => $j->approved_by])->all(),
        ];
    }

    public function propose(string $siteId, array $data, string $agentRunId): string
    {
        if (! in_array($data['type'], RecommendationEngine::TYPES, true)) {
            throw ApiException::conflict('invalid_recommendation_type', 'Unknown recommendation type.');
        }
        $fp = sha1('agent|'.$siteId.'|'.$data['type'].'|'.($data['article_id'] ?? '').'|'.($data['opportunity_id'] ?? '').'|'.$data['target_label']);
        $existing = SeoRecommendation::query()->where('site_id', $siteId)->where('fingerprint', $fp)->whereIn('status', ['proposed', 'approved', 'in_progress'])->first();
        if ($existing !== null) {
            return $existing->id;
        }
        $r = new SeoRecommendation;
        $r->forceFill([
            'site_id' => $siteId, 'opportunity_id' => $data['opportunity_id'] ?? null, 'fingerprint' => $fp, 'type' => $data['type'],
            'target_type' => isset($data['article_id']) ? 'article' : 'site', 'target_ref' => mb_substr((string) ($data['article_id'] ?? $siteId), 0, 1000),
            'target_label' => mb_substr($data['target_label'], 0, 500), 'article_id' => $data['article_id'] ?? null, 'evidence' => $data['evidence'],
            'rationale' => mb_substr($data['rationale'], 0, 4000), 'priority' => 50,
            'benefit_hypothesis' => 'Đề xuất của SEO Agent dựa trên dữ liệu đã quan sát; cần người duyệt và đo trước/sau (không cam kết kết quả).',
            'estimated_cost_micro_usd' => AnalyticsConfig::ACTION_COST[$data['type']] ?? 0, 'risk' => 'medium', 'required_approval' => 'editor',
            'status' => 'proposed', 'source' => 'agent', 'agent_run_id' => $agentRunId,
        ])->save();

        return $r->id;
    }

    public function startRefresh(string $articleId, ?string $recommendationId, string $userId, string $agentTaskId, string $idempotencyKey): array
    {
        $job = $this->refresh->create($articleId, $recommendationId, $userId, $agentTaskId, $idempotencyKey);

        return ['id' => $job->id, 'status' => $job->status];
    }

    public function refresh(string $refreshId): ?array
    {
        $j = ContentRefreshJob::query()->find($refreshId);

        return $j === null ? null : ['id' => $j->id, 'status' => $j->status, 'error_code' => $j->error_code, 'approved_by' => $j->approved_by, 'article_id' => $j->article_id];
    }

    public function publishRefresh(string $refreshId, string $userId): array
    {
        $j = ContentRefreshJob::query()->find($refreshId) ?? throw ApiException::notFound();
        if ($j->approved_by === null) {
            // Never publish content no human approved.
            throw ApiException::conflict('content_not_human_approved', 'Content must be approved by a person first.');
        }
        $j = $this->refresh->publish($j, $userId);

        return ['id' => $j->id, 'status' => $j->status];
    }

    public function experimentOutcomes(string $siteId, string $sinceDate): array
    {
        return SeoExperiment::query()->where('site_id', $siteId)->whereIn('status', ['completed', 'insufficient_data'])->where('observation_end', '>=', $sinceDate)
            ->orderBy('observation_end')->limit(50)->get()
            ->map(fn (SeoExperiment $e): array => ['id' => $e->id, 'change_type' => $e->change_type, 'outcome' => $e->outcome, 'page_url' => $e->page_url, 'observation_end' => $e->observation_end])
            ->all();
    }
}
