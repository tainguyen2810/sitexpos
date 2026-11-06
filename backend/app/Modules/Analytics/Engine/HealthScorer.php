<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

/**
 * SEO Health Score rubric (0–100). Each component is a percentage computed
 * from data the system really has; a component without data is marked
 * unavailable ("Chưa có dữ liệu") and its weight is excluded — nothing is
 * invented. This is an internal checklist, NOT a Google ranking score.
 */
final class HealthScorer
{
    /**
     * @param  array<string, array{value: float|null, detail: string}>  $components  value 0..100 or null = no data
     * @param  array<string, int>  $weights
     * @return array{score: int|null, components: array<string, array{value: float|null, weight: int, available: bool, detail: string}>}
     */
    public static function score(array $components, array $weights): array
    {
        $out = [];
        $sum = 0.0;
        $w = 0;
        foreach ($weights as $key => $weight) {
            $c = $components[$key] ?? ['value' => null, 'detail' => 'Chưa có dữ liệu'];
            $value = $c['value'] === null ? null : max(0.0, min(100.0, round($c['value'], 1)));
            $out[$key] = ['value' => $value, 'weight' => $weight, 'available' => $value !== null, 'detail' => $c['detail']];
            if ($value !== null && $weight > 0) {
                $sum += $value * $weight;
                $w += $weight;
            }
        }

        return ['score' => $w > 0 ? (int) round($sum / $w) : null, 'components' => $out];
    }
}
