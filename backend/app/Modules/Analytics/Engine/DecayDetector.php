<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

/**
 * Content decay (CONTENT DECAY ENGINE). Compares the recent window with
 * the previous comparable window, the historical baseline (mean of the
 * earlier windows) and — with 13 months of data — the same windows one
 * year earlier (seasonality). Query, URL and content changes are reported
 * as signals that COINCIDE with the decline; they are never presented as
 * the cause.
 *
 * Automatic outcomes: DECAY_SUSPECTED, NEEDS_INVESTIGATION (ambiguous:
 * seasonal pattern, URL or content change in the window) or null (no
 * decay). CONFIRMED_ISSUE / NO_ACTION are human decisions.
 */
final class DecayDetector
{
    public const SUSPECTED = 'DECAY_SUSPECTED';

    public const INVESTIGATE = 'NEEDS_INVESTIGATION';

    public const CONFIRMED = 'CONFIRMED_ISSUE';

    public const NO_ACTION = 'NO_ACTION';

    public const STATUSES = [self::SUSPECTED, self::INVESTIGATE, self::CONFIRMED, self::NO_ACTION];

    /**
     * @param  array{clicks: int, impressions: int, position: float|null}  $recent
     * @param  array{clicks: int, impressions: int, position: float|null}  $previous
     * @param  list<int>  $baselineClicks  clicks of earlier comparable windows (oldest first)
     * @param  array{recent: int, previous: int}|null  $lastYear  clicks of the same two windows one year earlier
     * @param  array{lost: list<array<string, mixed>>, gained: list<array<string, mixed>>}  $queryChanges
     * @param  list<string>  $urlChanges
     * @param  list<string>  $contentChanges  ISO dates of content updates inside the windows
     * @param  array<string, float|int>  $t
     * @return array{status: string, decline_pct: float, baseline: array<string, mixed>|null, seasonal: array<string, mixed>|null, signals: list<string>}|null
     */
    public static function evaluate(array $recent, array $previous, array $baselineClicks, ?array $lastYear, array $queryChanges, array $urlChanges, array $contentChanges, array $t): ?array
    {
        if ($previous['clicks'] < (int) $t['decay_min_clicks'] || $previous['impressions'] < (int) $t['min_impressions']) {
            return null; // not enough history to talk about decay
        }
        $decline = Metrics::change($recent['clicks'], $previous['clicks']);
        if ($decline === null || $decline > -(float) $t['decay_pct']) {
            return null;
        }
        $signals = [];
        $baseline = null;
        if ($baselineClicks !== []) {
            $mean = array_sum($baselineClicks) / count($baselineClicks);
            $vsBase = Metrics::change($recent['clicks'], $mean);
            $baseline = ['windows' => count($baselineClicks), 'mean_clicks' => round($mean, 1), 'change_pct' => $vsBase];
            if ($vsBase !== null && $vsBase > -(float) $t['decay_pct'] * 0.8) {
                // The previous window was a spike: compared with the long-run baseline the page is roughly normal.
                return null;
            }
            $signals[] = "Clicks giảm {$decline}% so với kỳ trước và ".($vsBase ?? 0)."% so với trung bình {$baseline['windows']} kỳ trước đó.";
        } else {
            $signals[] = "Clicks giảm {$decline}% so với kỳ trước (chưa đủ lịch sử để so với baseline dài hạn).";
        }

        $ambiguous = false;
        $seasonal = null;
        if ($lastYear !== null && $lastYear['previous'] > 0) {
            $lyChange = Metrics::change($lastYear['recent'], $lastYear['previous']);
            $seasonal = ['last_year_recent' => $lastYear['recent'], 'last_year_previous' => $lastYear['previous'], 'last_year_change_pct' => $lyChange];
            if ($lyChange !== null && $lyChange <= -(float) $t['decay_pct'] * 0.6) {
                $ambiguous = true;
                $signals[] = "Cùng kỳ năm trước clicks cũng giảm {$lyChange}% — có thể do mùa vụ.";
            }
        }
        if ($recent['position'] !== null && $previous['position'] !== null && $recent['position'] - $previous['position'] >= 2) {
            $signals[] = "Vị trí trung bình từ {$previous['position']} xuống {$recent['position']} (chỉ là một tín hiệu, không phải nguyên nhân).";
        }
        $imprChange = Metrics::change($recent['impressions'], $previous['impressions']);
        if ($imprChange !== null && $imprChange > -10) {
            $signals[] = "Impressions gần như không đổi ({$imprChange}%) trong khi clicks giảm — nên kiểm tra tiêu đề/mô tả hoặc thay đổi trang kết quả.";
        }
        if ($queryChanges['lost'] !== []) {
            $signals[] = count($queryChanges['lost']).' truy vấn từng mang lại click đã không còn xuất hiện trong kỳ gần đây.';
        }
        if ($urlChanges !== []) {
            $ambiguous = true;
            $signals[] = 'URL của bài đã thay đổi trong lịch sử ('.count($urlChanges).' URL) — dữ liệu có thể bị chia giữa các URL.';
        }
        if ($contentChanges !== []) {
            $ambiguous = true;
            $signals[] = 'Nội dung được cập nhật trong khoảng so sánh ('.implode(', ', array_slice($contentChanges, 0, 3)).') — trùng thời điểm, chưa đủ để kết luận nguyên nhân.';
        }

        return ['status' => $ambiguous ? self::INVESTIGATE : self::SUSPECTED, 'decline_pct' => $decline, 'baseline' => $baseline, 'seasonal' => $seasonal, 'signals' => $signals];
    }
}
