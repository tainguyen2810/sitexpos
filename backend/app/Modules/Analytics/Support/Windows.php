<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Comparable date windows ending on the last COMPLETE Search Console day,
 * and a single grouped query that sums each window (clicks, impressions,
 * impression-weighted position, days with data). Dates are computed by the
 * server, never taken from user input.
 */
final class Windows
{
    /**
     * @return array<string, array{0: string, 1: string}> recent, previous, base1..base3, ly_recent, ly_previous
     */
    public static function standard(CarbonImmutable $end, int $days = 28): array
    {
        $w = [];
        $w['recent'] = [$end->subDays($days - 1)->toDateString(), $end->toDateString()];
        $w['previous'] = [$end->subDays(2 * $days - 1)->toDateString(), $end->subDays($days)->toDateString()];
        for ($i = 1; $i <= 3; $i++) {
            $w['base'.$i] = [$end->subDays(($i + 2) * $days - 1)->toDateString(), $end->subDays(($i + 1) * $days)->toDateString()];
        }
        $ly = $end->subDays(364);
        $w['ly_recent'] = [$ly->subDays($days - 1)->toDateString(), $ly->toDateString()];
        $w['ly_previous'] = [$ly->subDays(2 * $days - 1)->toDateString(), $ly->subDays($days)->toDateString()];

        return $w;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  tenant-scoped builder already filtered by site
     * @param  array<string, array{0: string, 1: string}>  $windows
     * @param  list<string>  $extra  extra columns returned with MAX()
     * @return array<string, array<string, mixed>> key → [extra..., windows → [w → {clicks, impressions, position, days}]]
     */
    public static function aggregate(Builder $query, string $key, array $windows, array $extra = []): array
    {
        $select = [$key];
        $bindings = [];
        foreach ($extra as $col) {
            $select[] = "MAX({$col}) as {$col}";
        }
        $min = null;
        $max = null;
        foreach ($windows as $name => [$from, $to]) {
            $select[] = "SUM(CASE WHEN date BETWEEN ? AND ? THEN clicks ELSE 0 END) as {$name}_c";
            $select[] = "SUM(CASE WHEN date BETWEEN ? AND ? THEN impressions ELSE 0 END) as {$name}_i";
            $select[] = "SUM(CASE WHEN date BETWEEN ? AND ? THEN position * impressions ELSE 0 END) as {$name}_p";
            $select[] = "COUNT(DISTINCT CASE WHEN date BETWEEN ? AND ? THEN date END) as {$name}_d";
            array_push($bindings, $from, $to, $from, $to, $from, $to, $from, $to);
            $min = $min === null || $from < $min ? $from : $min;
            $max = $max === null || $to > $max ? $to : $max;
        }
        $rows = $query->selectRaw(implode(', ', $select), $bindings)->whereBetween('date', [$min, $max])->groupBy($key)->toBase()->get();
        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $item = [];
            foreach ($extra as $col) {
                $item[$col] = $r[$col];
            }
            foreach (array_keys($windows) as $name) {
                $i = (int) $r[$name.'_i'];
                $item['windows'][$name] = ['clicks' => (int) $r[$name.'_c'], 'impressions' => $i,
                    'position' => $i > 0 ? round((float) $r[$name.'_p'] / $i, 2) : null, 'days' => (int) $r[$name.'_d']];
            }
            $out[(string) $r[$key]] = $item;
        }

        return $out;
    }
}
