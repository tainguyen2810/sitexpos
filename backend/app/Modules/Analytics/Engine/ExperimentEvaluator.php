<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

/**
 * Before/after observation of one change (CONTENT EXPERIMENT TRACKING).
 * There is no control group, so this is never called an A/B test and the
 * result is an OBSERVED change, not an attributed impact. The site-wide
 * trend over the same windows is reported next to it as context.
 */
final class ExperimentEvaluator
{
    public const MIN_DAYS = 21;

    public const MIN_BASELINE_IMPRESSIONS = 50;

    public const THRESHOLD_PCT = 15.0;

    /**
     * @param  list<array{date: string, clicks: int, impressions: int, position: float|null}>  $baseline
     * @param  list<array{date: string, clicks: int, impressions: int, position: float|null}>  $observation
     * @param  array{baseline_clicks: int, observation_clicks: int}|null  $site  site totals for the same windows
     * @return array{outcome: string, baseline: array<string, mixed>, observed: array<string, mixed>, note: string}
     */
    public static function evaluate(array $baseline, array $observation, ?array $site = null): array
    {
        $b = self::summary($baseline);
        $o = self::summary($observation);
        $note = 'Đây là thay đổi QUAN SÁT ĐƯỢC trước/sau, không phải bằng chứng nhân quả: mùa vụ, cập nhật thuật toán, đối thủ hoặc thay đổi khác cũng có thể ảnh hưởng.';
        if ($b['days'] < self::MIN_DAYS || $o['days'] < self::MIN_DAYS || $b['impressions'] < self::MIN_BASELINE_IMPRESSIONS) {
            return ['outcome' => 'insufficient_data', 'baseline' => $b, 'observed' => $o,
                'note' => 'Chưa đủ dữ liệu (cần ≥ '.self::MIN_DAYS.' ngày mỗi giai đoạn và ≥ '.self::MIN_BASELINE_IMPRESSIONS.' lượt hiển thị trước thay đổi). '.$note];
        }
        $clicksChange = Metrics::change($o['clicks_per_day'], $b['clicks_per_day']);
        $imprChange = Metrics::change($o['impressions_per_day'], $b['impressions_per_day']);
        $o['clicks_change_pct'] = $clicksChange;
        $o['impressions_change_pct'] = $imprChange;
        $o['ctr_change_pts'] = $o['ctr'] !== null && $b['ctr'] !== null ? round($o['ctr'] - $b['ctr'], 2) : null;
        $o['position_change'] = $o['position'] !== null && $b['position'] !== null ? round($b['position'] - $o['position'], 2) : null;
        if ($site !== null && $site['baseline_clicks'] > 0) {
            $o['site_clicks_change_pct'] = Metrics::change($site['observation_clicks'], $site['baseline_clicks']);
            $o['relative_to_site_pts'] = $clicksChange !== null && $o['site_clicks_change_pct'] !== null ? round($clicksChange - $o['site_clicks_change_pct'], 1) : null;
        }
        $outcome = match (true) {
            $clicksChange === null => 'no_clear_change',
            $clicksChange >= self::THRESHOLD_PCT && ($imprChange === null || $imprChange > -self::THRESHOLD_PCT) => 'improved',
            $clicksChange <= -self::THRESHOLD_PCT => 'declined',
            default => 'no_clear_change',
        };

        return ['outcome' => $outcome, 'baseline' => $b, 'observed' => $o, 'note' => $note];
    }

    /**
     * @param  list<array{date: string, clicks: int, impressions: int, position: float|null}>  $rows
     * @return array{days: int, clicks: int, impressions: int, ctr: float|null, position: float|null, clicks_per_day: float, impressions_per_day: float}
     */
    private static function summary(array $rows): array
    {
        $agg = Metrics::aggregate($rows);
        $days = count($rows);

        return $agg + ['days' => $days, 'clicks_per_day' => $days > 0 ? round($agg['clicks'] / $days, 2) : 0.0,
            'impressions_per_day' => $days > 0 ? round($agg['impressions'] / $days, 2) : 0.0];
    }
}
