<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

/**
 * Content performance classification (CONTENT PERFORMANCE CLASSIFICATION).
 *
 * Primary class (one): RECENTLY_PUBLISHED > INSUFFICIENT_DATA > DECLINING >
 * GROWING > UNDERPERFORMING > STABLE. Extra labels (any): high_impr_low_ctr,
 * near_top, decay_candidate, needs_refresh.
 *
 * Rules never rest on average position alone: growth/decline need clicks
 * AND impressions; "underperforming" needs position AND CTR; "near top"
 * needs enough impressions. Young pages are never judged as failures.
 */
final class TrendClassifier
{
    public const GROWING = 'GROWING';

    public const STABLE = 'STABLE';

    public const DECLINING = 'DECLINING';

    public const UNDERPERFORMING = 'UNDERPERFORMING';

    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public const RECENTLY_PUBLISHED = 'RECENTLY_PUBLISHED';

    public const CLASSES = [self::GROWING, self::STABLE, self::DECLINING, self::UNDERPERFORMING, self::INSUFFICIENT_DATA, self::RECENTLY_PUBLISHED];

    public const LABELS = ['high_impr_low_ctr', 'near_top', 'decay_candidate', 'needs_refresh'];

    /**
     * @param  array{clicks: int, impressions: int, position: float|null, days_with_data: int}  $recent
     * @param  array{clicks: int, impressions: int, position: float|null}  $previous
     * @param  array<string, float|int>  $t  thresholds (AnalyticsConfig)
     * @param  float|null  $bandMedianCtr  site median CTR (%) for this page's position band, null when unknown
     * @param  int|null  $ageDays  days since publication (null = unknown)
     * @param  int|null  $daysSinceUpdate  days since the last content update (null = unknown)
     * @return array{classification: string, labels: list<string>, reasons: list<string>, clicks_change: float|null, impressions_change: float|null}
     */
    public static function classify(array $recent, array $previous, array $t, ?float $baselineClicks = null, ?float $bandMedianCtr = null, ?int $ageDays = null, ?int $daysSinceUpdate = null): array
    {
        $clicksChange = Metrics::change($recent['clicks'], $previous['clicks']);
        $imprChange = Metrics::change($recent['impressions'], $previous['impressions']);
        $ctr = Metrics::ctr($recent['clicks'], $recent['impressions']);
        $reasons = [];
        $labels = [];

        if ($ageDays !== null && $ageDays < (int) $t['recent_publish_days']) {
            return ['classification' => self::RECENTLY_PUBLISHED, 'labels' => [], 'clicks_change' => $clicksChange, 'impressions_change' => $imprChange,
                'reasons' => ["Xuất bản {$ageDays} ngày trước (< {$t['recent_publish_days']} ngày) — chưa đủ thời gian để đánh giá."]];
        }
        if ($recent['impressions'] < (int) $t['min_impressions'] || $recent['days_with_data'] < (int) $t['min_days_with_data']) {
            return ['classification' => self::INSUFFICIENT_DATA, 'labels' => [], 'clicks_change' => $clicksChange, 'impressions_change' => $imprChange,
                'reasons' => ["Chỉ có {$recent['impressions']} lượt hiển thị trong {$recent['days_with_data']} ngày có dữ liệu (cần ≥ {$t['min_impressions']} lượt và ≥ {$t['min_days_with_data']} ngày)."]];
        }

        $class = self::STABLE;
        $enoughBase = $previous['impressions'] >= (int) $t['min_impressions'];
        if ($enoughBase && $clicksChange !== null && $imprChange !== null) {
            if ($clicksChange <= (float) $t['declining_pct'] && $imprChange < 0) {
                $class = self::DECLINING;
                $reasons[] = "Clicks {$clicksChange}% và impressions {$imprChange}% so với kỳ trước.";
            } elseif ($clicksChange >= (float) $t['growing_pct'] && $imprChange >= 0) {
                $class = self::GROWING;
                $reasons[] = "Clicks +{$clicksChange}% và impressions +{$imprChange}% so với kỳ trước.";
            }
        } elseif (! $enoughBase) {
            $reasons[] = 'Kỳ trước chưa đủ dữ liệu để so sánh xu hướng.';
        }
        if ($class === self::STABLE && $recent['position'] !== null && $ctr !== null
            && $recent['position'] > (float) $t['underperforming_position'] && $ctr < (float) $t['underperforming_ctr_pct']) {
            $class = self::UNDERPERFORMING;
            $reasons[] = "Vị trí trung bình {$recent['position']} và CTR {$ctr}% (ngưỡng: vị trí > {$t['underperforming_position']} và CTR < {$t['underperforming_ctr_pct']}%).";
        }
        if ($class === self::STABLE) {
            $reasons[] = 'Không có thay đổi vượt ngưỡng so với kỳ trước.';
        }

        // Labels.
        if ($recent['impressions'] >= (int) $t['high_impressions'] && $ctr !== null) {
            $limit = $bandMedianCtr !== null ? $bandMedianCtr * (float) $t['low_ctr_ratio'] : (float) $t['low_ctr_fallback_pct'];
            if ($ctr < $limit) {
                $labels[] = 'high_impr_low_ctr';
                $reasons[] = $bandMedianCtr !== null
                    ? "CTR {$ctr}% thấp hơn ".((float) $t['low_ctr_ratio'] * 100)."% trung vị CTR của chính website ở cùng nhóm vị trí ({$bandMedianCtr}%)."
                    : "CTR {$ctr}% < {$t['low_ctr_fallback_pct']}% (chưa đủ trang cùng nhóm vị trí để so sánh nội bộ).";
            }
        }
        if ($recent['position'] !== null && $recent['position'] >= (float) $t['near_top_min_position'] && $recent['position'] <= (float) $t['near_top_max_position']) {
            $labels[] = 'near_top';
        }
        $declineVsBase = $baselineClicks !== null && $baselineClicks > 0 ? Metrics::change($recent['clicks'], $baselineClicks) : null;
        if ($previous['clicks'] >= (int) $t['decay_min_clicks'] && $clicksChange !== null && $clicksChange <= -(float) $t['decay_pct']
            && ($declineVsBase === null || $declineVsBase <= -(float) $t['decay_pct'] * 0.8)) {
            $labels[] = 'decay_candidate';
        }
        if ($daysSinceUpdate !== null && $daysSinceUpdate >= (int) $t['refresh_age_days'] && ($class === self::DECLINING || in_array('decay_candidate', $labels, true))) {
            $labels[] = 'needs_refresh';
            $reasons[] = "Nội dung chưa cập nhật {$daysSinceUpdate} ngày và đang giảm hiệu quả.";
        }

        return ['classification' => $class, 'labels' => $labels, 'reasons' => $reasons, 'clicks_change' => $clicksChange, 'impressions_change' => $imprChange];
    }
}
