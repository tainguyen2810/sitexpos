<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Support;

use SiteXPost\Modules\Analytics\Domain\AnalyticsSetting;

/**
 * Configurable thresholds (CONTENT PERFORMANCE / DECAY / OPPORTUNITY rules)
 * and SEO Health weights. Workspace overrides live in analytics_settings;
 * anything missing falls back to these defaults (SEO-OPPORTUNITY-RULES.md).
 */
final class AnalyticsConfig
{
    public const THRESHOLDS = [
        'window_days' => 28,
        'min_impressions' => 100,          // minimum data before any judgement
        'min_days_with_data' => 14,
        'recent_publish_days' => 28,        // younger pages are "recently published", never "failed"
        'growing_pct' => 20.0,
        'declining_pct' => -20.0,
        'underperforming_position' => 20.0,
        'underperforming_ctr_pct' => 1.0,
        'low_ctr_ratio' => 0.5,             // CTR below 50% of the site's own median at the same position band
        'low_ctr_fallback_pct' => 1.0,      // used when the band has < 5 pages
        'high_impressions' => 500,
        'near_top_min_position' => 4.0,
        'near_top_max_position' => 15.0,
        'decay_pct' => 25.0,
        'decay_min_clicks' => 20,
        'refresh_age_days' => 365,
        'opp_min_impressions' => 200,
        'new_query_min_impressions' => 50,
        'cannibal_min_impressions' => 100,
        'cannibal_min_share' => 0.2,
        'gap_min_impressions' => 100,
        'gap_min_position' => 30.0,
        'improving_positions' => 3.0,
        'outdated_years' => 2,
    ];

    public const HEALTH_WEIGHTS = [
        'content_freshness' => 20,
        'metadata_completeness' => 15,
        'internal_linking' => 15,
        'broken_links' => 15,
        'content_coverage' => 15,
        'technical_signals' => 10,
        'publishing_consistency' => 5,
        'fact_check_issues' => 5,
    ];

    /** Estimated AI cost per recommended action (micro-USD). An estimate, never a quote. */
    public const ACTION_COST = [
        'rewrite_title_meta' => 20_000,
        'add_missing_sections' => 250_000,
        'update_outdated_facts' => 300_000,
        'refresh_content' => 400_000,
        'improve_internal_links' => 10_000,
        'merge_overlapping_articles' => 0,
        'create_supporting_article' => 900_000,
        'expand_topic_cluster' => 900_000,
        'fix_broken_links' => 0,
        'improve_article_structure' => 150_000,
        'fix_metadata' => 20_000,
        'investigate' => 0,
    ];

    /**
     * @return array<string, float|int>
     */
    public static function thresholds(?string $workspaceId = null): array
    {
        $over = $workspaceId !== null ? (AnalyticsSetting::query()->where('workspace_id', $workspaceId)->first()->thresholds ?? []) : [];
        $out = self::THRESHOLDS;
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $out) && is_numeric($v)) {
                $out[$k] = is_int(self::THRESHOLDS[$k]) ? (int) $v : (float) $v;
            }
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public static function weights(?string $workspaceId = null): array
    {
        $over = $workspaceId !== null ? (AnalyticsSetting::query()->where('workspace_id', $workspaceId)->first()->health_weights ?? []) : [];
        $out = self::HEALTH_WEIGHTS;
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $out) && is_numeric($v)) {
                $out[$k] = max(0, min(100, (int) $v));
            }
        }

        return $out;
    }

    public static function backfillDays(?string $workspaceId): int
    {
        $v = $workspaceId !== null ? AnalyticsSetting::query()->where('workspace_id', $workspaceId)->value('backfill_days') : null;

        return max(7, min((int) config('sitex.analytics.max_backfill_days', 480), (int) ($v ?? config('sitex.analytics.backfill_days', 90))));
    }
}
