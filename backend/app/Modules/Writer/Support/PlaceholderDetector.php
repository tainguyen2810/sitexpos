<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

/**
 * Finds "placeholder" text in written content: table cells that only say
 * "Cần xác minh", sentences that talk about the missing sources instead of
 * the topic ("bài này chưa có điều khoản chính thức…", "chưa có nguồn nên
 * chưa thể kết luận"). Such text is not a careful hedge, it is an empty
 * article: the writer is asked to rewrite it once, and a version that still
 * contains it cannot be approved (a human has to fill or remove it).
 */
final class PlaceholderDetector
{
    /** Sentence-level patterns (lower-case, matched on plain text). */
    private const PATTERNS = [
        '/(*UCP)\b(cần|chờ|đang chờ|còn chờ|phải) (được )?(xác minh|kiểm chứng)\b/u',
        '/(*UCP)\bchưa (có|tìm thấy|tìm được|đủ|thu thập được) (nguồn|bằng chứng|tài liệu|điều khoản|thông tin|dữ liệu)( [\p{L}]+){0,3} (chính thức|xác thực|kiểm chứng|đáng tin|gốc)\b/u',
        '/(*UCP)\bchưa có nguồn\b/u',
        '/(*UCP)\bchưa (thể|đủ cơ sở để) (kết luận|xác nhận|khẳng định|nêu|điền)\b/u',
        '/(*UCP)\b(trong tay|đang chờ bổ sung|sẽ được cập nhật sau|cập nhật sau khi có nguồn)\b/u',
        '/(*UCP)\[(cần|tbd|todo|placeholder)[^\]]*\]/u',
        '/(*UCP)\b(tbd|lorem ipsum)\b/u',
    ];

    /** Cell text that is nothing but a placeholder. */
    private const CELL = '/^\s*(cần xác minh|chờ xác minh|đang chờ xác minh|chưa xác minh|chưa có (dữ liệu|nguồn|thông tin)|không rõ|n\/a|tbd|\?|—|-|…)\s*\.?\s*$/u';

    /**
     * @return array{hollow: bool, cells: int, phrases: list<string>}
     */
    public static function scan(string $html): array
    {
        $cells = 0;
        if (preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $html, $m) > 0) {
            foreach ($m[1] as $cell) {
                if (preg_match(self::CELL, mb_strtolower(trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8')))) === 1) {
                    $cells++;
                }
            }
        }
        $text = mb_strtolower(HtmlSanitizer::text($html));
        $phrases = [];
        foreach (self::PATTERNS as $p) {
            if (preg_match_all($p, $text, $pm) > 0) {
                foreach ($pm[0] as $hit) {
                    $phrases[] = trim($hit);
                }
            }
        }
        $phrases = array_values(array_unique($phrases));

        // One cautious sentence is fine; placeholder cells or repeated
        // "no source" talk are not.
        return ['hollow' => $cells >= 2 || count($phrases) >= 2, 'cells' => $cells, 'phrases' => array_slice($phrases, 0, 10)];
    }
}
