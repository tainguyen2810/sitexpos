<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http;

use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Domain\ContentDecayEvent;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\CopilotQuery;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Domain\SeoReport;
use SiteXPost\Modules\Analytics\Engine\Metrics;

/**
 * API shapes for Analytics rows. Tokens, property credentials and raw
 * provider responses are never exposed.
 */
final class AnalyticsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function syncRun(AnalyticsSyncRun $r): array
    {
        return [
            'id' => $r->id, 'site_id' => $r->site_id, 'provider' => $r->provider, 'kind' => $r->kind, 'status' => $r->status,
            'range_start' => $r->range_start, 'range_end' => $r->range_end, 'checkpoint_date' => $r->checkpoint_date,
            'rows_upserted' => $r->rows_upserted, 'api_requests' => $r->api_requests, 'attempts' => $r->attempts,
            'error_code' => $r->error_code, 'error_message' => $r->error_message, 'next_retry_at' => $r->next_retry_at?->toIso8601ZuluString(),
            'started_at' => $r->started_at?->toIso8601ZuluString(), 'finished_at' => $r->finished_at?->toIso8601ZuluString(),
            'job_run_id' => $r->job_run_id, 'is_demo' => $r->is_demo, 'created_at' => $r->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(ContentPerformanceSnapshot $s): array
    {
        return [
            'id' => $s->id, 'site_id' => $s->site_id, 'page_url' => $s->page_url, 'article_id' => $s->article_id, 'period_end' => $s->period_end,
            'window_days' => $s->window_days, 'clicks' => $s->clicks, 'impressions' => $s->impressions,
            'ctr' => Metrics::ctr($s->clicks, $s->impressions), 'position' => $s->position !== null ? (float) $s->position : null,
            'prev_clicks' => $s->prev_clicks, 'prev_impressions' => $s->prev_impressions, 'prev_position' => $s->prev_position !== null ? (float) $s->prev_position : null,
            'baseline_clicks' => $s->baseline_clicks !== null ? (float) $s->baseline_clicks : null, 'yoy_clicks' => $s->yoy_clicks,
            'days_with_data' => $s->days_with_data, 'classification' => $s->classification, 'labels' => $s->labels ?? [], 'reasons' => $s->reasons ?? [],
            'published_at' => $s->published_at?->toIso8601ZuluString(), 'is_demo' => $s->is_demo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function opportunity(SeoOpportunity $o): array
    {
        return [
            'id' => $o->id, 'site_id' => $o->site_id, 'rule_code' => $o->rule_code, 'rule_version' => $o->rule_version,
            'target_type' => $o->target_type, 'target_ref' => $o->target_ref, 'target_label' => $o->target_label, 'article_id' => $o->article_id,
            'evidence' => $o->evidence, 'detection_rule' => $o->detection_rule, 'severity' => $o->severity, 'impact' => $o->impact,
            'impact_basis' => $o->impact_basis, 'confidence' => $o->confidence, 'confidence_explanation' => $o->confidence_explanation,
            'recommended_action' => $o->recommended_action, 'estimated_ai_cost_micro_usd' => $o->estimated_ai_cost_micro_usd,
            'status' => $o->status, 'review_note' => $o->review_note, 'reviewed_at' => $o->reviewed_at?->toIso8601ZuluString(),
            'first_detected_at' => $o->first_detected_at?->toIso8601ZuluString(), 'last_detected_at' => $o->last_detected_at?->toIso8601ZuluString(),
            'detections' => $o->detections, 'is_demo' => $o->is_demo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decay(ContentDecayEvent $d): array
    {
        return [
            'id' => $d->id, 'site_id' => $d->site_id, 'page_url' => $d->page_url, 'article_id' => $d->article_id, 'status' => $d->status,
            'decline_pct' => (float) $d->decline_pct, 'recent' => $d->recent, 'previous' => $d->previous, 'baseline' => $d->baseline,
            'seasonal' => $d->seasonal, 'query_changes' => $d->query_changes, 'url_changes' => $d->url_changes, 'content_changes' => $d->content_changes,
            'signals' => $d->signals, 'detected_at' => $d->detected_at?->toIso8601ZuluString(), 'last_evaluated_at' => $d->last_evaluated_at?->toIso8601ZuluString(),
            'decided_at' => $d->decided_at?->toIso8601ZuluString(), 'decision_note' => $d->decision_note, 'is_demo' => $d->is_demo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function recommendation(SeoRecommendation $r): array
    {
        return [
            'id' => $r->id, 'site_id' => $r->site_id, 'opportunity_id' => $r->opportunity_id, 'type' => $r->type,
            'target_type' => $r->target_type, 'target_ref' => $r->target_ref, 'target_label' => $r->target_label, 'article_id' => $r->article_id,
            'evidence' => $r->evidence, 'rationale' => $r->rationale, 'priority' => $r->priority, 'benefit_hypothesis' => $r->benefit_hypothesis,
            'estimated_cost_micro_usd' => $r->estimated_cost_micro_usd, 'risk' => $r->risk, 'risk_notes' => $r->risk_notes,
            'required_approval' => $r->required_approval, 'status' => $r->status, 'suggested_value' => $r->suggested_value, 'source' => $r->source,
            'agent_run_id' => $r->agent_run_id, 'decided_at' => $r->decided_at?->toIso8601ZuluString(), 'decision_note' => $r->decision_note,
            'applied_at' => $r->applied_at?->toIso8601ZuluString(), 'is_demo' => $r->is_demo, 'created_at' => $r->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refresh(ContentRefreshJob $j, bool $full = false): array
    {
        $base = [
            'id' => $j->id, 'site_id' => $j->site_id, 'article_id' => $j->article_id, 'recommendation_id' => $j->recommendation_id,
            'agent_task_id' => $j->agent_task_id, 'status' => $j->status, 'error_code' => $j->error_code, 'error_message' => $j->error_message,
            'estimated_cost_micro_usd' => $j->estimated_cost_micro_usd, 'publishing_job_id' => $j->publishing_job_id, 'experiment_id' => $j->experiment_id,
            'approved_at' => $j->approved_at?->toIso8601ZuluString(), 'is_demo' => $j->is_demo,
            'created_at' => $j->created_at?->toIso8601ZuluString(), 'updated_at' => $j->updated_at?->toIso8601ZuluString(),
        ];

        return $full ? $base + [
            'wp_check' => $j->wp_check, 'base_version_id' => $j->base_version_id, 'new_version_id' => $j->new_version_id,
            'analysis' => $j->analysis, 'brief' => $j->brief, 'diff' => $j->diff, 'fact_check' => $j->fact_check, 'seo_audit' => $j->seo_audit,
        ] : $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function experiment(SeoExperiment $e): array
    {
        return [
            'id' => $e->id, 'site_id' => $e->site_id, 'article_id' => $e->article_id, 'page_url' => $e->page_url, 'change_type' => $e->change_type,
            'change_ref' => $e->change_ref, 'method' => $e->method, 'change_date' => $e->change_date?->toIso8601ZuluString(),
            'baseline_start' => $e->baseline_start, 'baseline_end' => $e->baseline_end, 'observation_days' => $e->observation_days,
            'observation_end' => $e->observation_end, 'target_metrics' => $e->target_metrics, 'baseline' => $e->baseline, 'observed' => $e->observed,
            'status' => $e->status, 'outcome' => $e->outcome, 'outcome_note' => $e->outcome_note, 'external_factors' => $e->external_factors,
            'is_demo' => $e->is_demo, 'created_at' => $e->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function health(SeoHealthScore $h): array
    {
        return ['id' => $h->id, 'site_id' => $h->site_id, 'date' => $h->date, 'score' => $h->score, 'components' => $h->components, 'weights' => $h->weights, 'is_demo' => $h->is_demo];
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(SeoReport $r, bool $full = false): array
    {
        $base = [
            'id' => $r->id, 'site_id' => $r->site_id, 'period_type' => $r->period_type, 'period_start' => $r->period_start, 'period_end' => $r->period_end,
            'status' => $r->status, 'error_code' => $r->error_code, 'emailed_at' => $r->emailed_at?->toIso8601ZuluString(),
            'recipients_count' => $r->recipients_count, 'is_demo' => $r->is_demo, 'created_at' => $r->created_at?->toIso8601ZuluString(),
        ];

        return $full ? $base + ['payload' => $r->payload] : $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function copilot(CopilotQuery $q): array
    {
        return [
            'id' => $q->id, 'site_id' => $q->site_id, 'question' => $q->question, 'intent' => $q->intent, 'answer' => $q->answer,
            'mode' => $q->mode, 'cost_micro_usd' => $q->cost_micro_usd, 'created_at' => $q->created_at?->toIso8601ZuluString(),
        ];
    }
}
