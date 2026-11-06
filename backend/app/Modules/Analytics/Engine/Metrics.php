<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

/**
 * Metric arithmetic shared by every engine. CTR is always recomputed from
 * summed clicks / impressions (never averaged) and position is
 * impression-weighted, like Search Console does for aggregated rows.
 */
final class Metrics
{
    /**
     * @param  iterable<array{clicks: int|float, impressions: int|float, position: int|float|null}>  $rows
     * @return array{clicks: int, impressions: int, ctr: float|null, position: float|null}
     */
    public static function aggregate(iterable $rows): array
    {
        $clicks = 0;
        $impr = 0;
        $weighted = 0.0;
        foreach ($rows as $r) {
            $clicks += (int) $r['clicks'];
            $i = (int) $r['impressions'];
            $impr += $i;
            if ($r['position'] !== null) {
                $weighted += (float) $r['position'] * $i;
            }
        }

        return [
            'clicks' => $clicks,
            'impressions' => $impr,
            'ctr' => self::ctr($clicks, $impr),
            'position' => $impr > 0 ? round($weighted / $impr, 2) : null,
        ];
    }

    public static function ctr(int $clicks, int $impressions): ?float
    {
        return $impressions > 0 ? round($clicks / $impressions * 100, 2) : null;
    }

    /** Percentage change; null when the base is zero (no "infinite growth"). */
    public static function change(int|float $current, int|float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    public static function positionBand(?float $position): string
    {
        return match (true) {
            $position === null => 'none',
            $position <= 3 => '1-3',
            $position <= 10 => '4-10',
            $position <= 20 => '11-20',
            default => '20+',
        };
    }

    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
