<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

/**
 * "Is this excerpt really in the source?" — the core anti-fabrication check.
 * Only typography is normalised (case, whitespace, quote and dash variants);
 * words, numbers and model names must match exactly.
 */
final class Verbatim
{
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{00AB}", "\u{00BB}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{00A0}", "\u{200B}", "\u{2026}"],
            ["'", "'", '"', '"', '"', '"', '-', '-', '-', ' ', '', '...'], $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return trim($s, " \t\n\r\0\x0B.,;:");
    }

    public static function contains(string $haystack, string $needle, int $minLength = 20): bool
    {
        $n = self::norm($needle);
        if (mb_strlen($n) < $minLength) {
            return false;
        }

        return mb_strpos(self::norm($haystack), $n) !== false;
    }
}
