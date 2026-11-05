<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

/**
 * Sentence/line-level diff (LCS) used for version comparison and for the
 * before/after record of every revision. Bounded: inputs are capped so a
 * huge article cannot exhaust memory.
 */
final class TextDiff
{
    private const MAX_UNITS = 1500;

    /**
     * @return list<array{op: 'eq'|'add'|'del', text: string}>
     */
    public static function diff(string $before, string $after): array
    {
        $a = array_slice(self::units($before), 0, self::MAX_UNITS);
        $b = array_slice(self::units($after), 0, self::MAX_UNITS);
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => 'eq', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => 'del', 'text' => $a[$i++]];
            } else {
                $out[] = ['op' => 'add', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['op' => 'del', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['op' => 'add', 'text' => $b[$j++]];
        }

        return $out;
    }

    /**
     * Share of units that changed (0..1).
     */
    public static function changeRatio(string $before, string $after): float
    {
        $ops = self::diff($before, $after);
        if ($ops === []) {
            return 0.0;
        }
        $changed = count(array_filter($ops, static fn (array $o): bool => $o['op'] !== 'eq'));
        $base = max(1, count(array_filter($ops, static fn (array $o): bool => $o['op'] !== 'add')));

        return min(1.0, $changed / (2 * $base));
    }

    /**
     * @return list<array{op: string, text: string}>
     */
    public static function compact(array $ops): array
    {
        return array_values(array_filter($ops, static fn (array $o): bool => $o['op'] !== 'eq'));
    }

    /**
     * @return list<string>
     */
    public static function units(string $text): array
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $units = [];
        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            foreach (preg_split('/(?<=[.!?…])\s+(?=\p{Lu}|\d|")/u', trim($line)) ?: [] as $s) {
                $s = trim($s);
                if ($s !== '') {
                    $units[] = $s;
                }
            }
        }

        return $units;
    }
}
