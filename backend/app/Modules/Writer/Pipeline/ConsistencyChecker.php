<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\Identifiers;

/**
 * Rule-based factual consistency of one version (no AI): similar model
 * names used together, one model with two different values for the same
 * unit, table vs text mismatches, mixed units, impossible / future dates,
 * "latest" wording next to old years.
 */
final class ConsistencyChecker
{
    /**
     * @return list<array{type: string, severity: string, message: string}>
     */
    public static function check(string $html): array
    {
        $issues = [];
        $text = HtmlSanitizer::text($html);
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];

        // 1. Similar model codes (AX200 / AX201 / AX210 / AX211 ...).
        $codes = [];
        foreach ($sentences as $s) {
            foreach (Identifiers::of($s) as $id) {
                if (Identifiers::isModelCode($id)) {
                    $codes[$id] = true;
                }
            }
        }
        $list = array_keys($codes);
        $pairs = [];
        foreach ($list as $i => $a) {
            foreach (array_slice($list, $i + 1) as $b) {
                if ($a !== $b && self::confusable($a, $b)) {
                    $pairs[] = "{$a} / {$b}";
                }
            }
        }
        if ($pairs !== []) {
            $issues[] = ['type' => 'similar_model_names', 'severity' => 'warning',
                'message' => 'Bài viết nhắc các model gần giống nhau: '.implode(', ', array_slice($pairs, 0, 6)).'. Kiểm tra mỗi thông số gắn đúng model.'];
        }

        // 2. Same model, same unit, different values.
        $values = [];
        foreach ($sentences as $s) {
            $ids = Identifiers::of($s);
            $models = array_values(array_filter($ids, [Identifiers::class, 'isModelCode']));
            if (count($models) !== 1) {
                continue;
            }
            foreach ($ids as $id) {
                if (preg_match('/^(\d+(?:\.\d+)?)([^\d.].*)$/u', $id, $m) === 1 && ! in_array($m[2], ['%', 'NĂM', 'THÁNG', 'NGÀY', 'LẦN', 'X'], true)) {
                    $values[$models[0]][$m[2]][$m[1]] = true;
                }
            }
        }
        foreach ($values as $model => $units) {
            foreach ($units as $unit => $nums) {
                if (count($nums) > 1) {
                    $issues[] = ['type' => 'inconsistent_values', 'severity' => 'warning',
                        'message' => "{$model} được ghi nhiều giá trị {$unit} khác nhau: ".implode(', ', array_keys($nums)).'. Kiểm tra lại (có thể đúng nếu nói về chế độ khác nhau).'];
                }
            }
        }

        // 3. Table vs text.
        if (preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $rows) > 0) {
            $body = HtmlSanitizer::text((string) preg_replace('#<table\b.*?</table>#is', '', $html));
            foreach ($rows[1] as $row) {
                preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $row, $cells);
                $cells = array_map(static fn ($c) => trim(html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $cells[1] ?? []);
                if (count($cells) < 2 || mb_strlen($cells[0]) < 2) {
                    continue;
                }
                $label = $cells[0];
                foreach (array_slice($cells, 1) as $cell) {
                    foreach (Identifiers::of($cell) as $tv) {
                        if (preg_match('/^(\d+(?:\.\d+)?)(.+)$/u', $tv, $m) !== 1) {
                            continue;
                        }
                        foreach ($sentences as $s) {
                            if (mb_stripos($s, $label) === false || mb_strpos($body, $s) === false) {
                                continue;
                            }
                            foreach (Identifiers::of($s) as $sv) {
                                if (preg_match('/^(\d+(?:\.\d+)?)(.+)$/u', $sv, $n) === 1 && $n[2] === $m[2] && $n[1] !== $m[1]) {
                                    $issues[] = ['type' => 'table_text_mismatch', 'severity' => 'warning',
                                        'message' => "Bảng ghi {$label}: {$m[1]}{$m[2]} nhưng nội dung ghi {$n[1]}{$n[2]}."];
                                }
                            }
                        }
                    }
                }
            }
        }

        // 4. Mixed units for throughput.
        $l = mb_strtolower($text);
        if (preg_match('/\d\s?mbps/u', $l) === 1 && preg_match('/\d\s?mb\/s/u', $l) === 1) {
            $issues[] = ['type' => 'unit_mix', 'severity' => 'info', 'message' => 'Bài viết dùng cả Mbps (megabit) và MB/s (megabyte) — 1 MB/s = 8 Mbps, kiểm tra không nhầm đơn vị.'];
        }

        // 5. Dates.
        if (preg_match_all('#\b(\d{1,2})/(\d{1,2})/((?:19|20)\d{2})\b#', $text, $dm, PREG_SET_ORDER) > 0) {
            foreach ($dm as $d) {
                if (! checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
                    $issues[] = ['type' => 'invalid_date', 'severity' => 'warning', 'message' => "Ngày không hợp lệ: {$d[0]}."];
                } elseif (mktime(0, 0, 0, (int) $d[2], (int) $d[1], (int) $d[3]) > strtotime('+1 year')) {
                    $issues[] = ['type' => 'future_date', 'severity' => 'warning', 'message' => "Ngày ở tương lai xa: {$d[0]}."];
                }
            }
        }
        if (preg_match('/(mới nhất|hiện nay|hiện tại|năm nay)/u', $l) === 1 && preg_match_all('/\b(20\d{2})\b/u', $l, $ym) > 0) {
            $old = array_filter(array_map('intval', $ym[1]), static fn (int $y): bool => $y <= (int) date('Y') - 3);
            if ($old !== [] && max(array_map('intval', $ym[1])) <= (int) date('Y') - 3) {
                $issues[] = ['type' => 'possibly_outdated', 'severity' => 'warning', 'message' => 'Bài viết dùng từ "mới nhất/hiện nay" nhưng mốc thời gian gần nhất là '.max($old).' — có thể đã lỗi thời.'];
            }
        }

        return $issues;
    }

    /**
     * AX200 / AX201 / AX210 / AX211 (same family, ≤ 2 edits) and AX200 / BE200
     * (same number, different family) are easy to mix up.
     */
    public static function confusable(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b) || preg_match('/^([A-Z]+)(\d+)([A-Z]*)$/', $a, $x) !== 1 || preg_match('/^([A-Z]+)(\d+)([A-Z]*)$/', $b, $y) !== 1) {
            return strlen($a) === strlen($b) && levenshtein($a, $b) === 1;
        }

        return ($x[1] === $y[1] && levenshtein($x[2], $y[2]) <= 2) || ($x[2] === $y[2] && $x[1] !== $y[1]);
    }
}
