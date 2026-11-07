<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\PlaceholderDetector;

/**
 * Deterministic quality rubric v1 (CONTENT-QUALITY-RUBRIC.md). 100 points,
 * every point traceable to a measured fact of the version. It is an
 * editorial checklist: it never predicts Google rankings and it is never the
 * only condition for approval (a human approves).
 */
final class QualityScorer
{
    public const RUBRIC_VERSION = 'v1';

    public const BOILERPLATE = ['trong thời đại công nghệ', 'trong thời đại ngày nay', 'không thể phủ nhận', 'ngày càng phát triển', 'đóng vai trò vô cùng quan trọng',
        'hãy cùng tìm hiểu', 'bài viết dưới đây', 'như chúng ta đã biết', 'có thể nói rằng', 'thật vậy', 'không còn xa lạ', 'là một trong những'];

    /**
     * @param  array<string, mixed>|null  $duplicates
     * @param  list<array<string, mixed>>  $consistency
     * @return array{score: int, criteria: list<array{key: string, score: float, max: int, notes: list<string>}>, issues: list<array{severity: string, message: string}>}
     */
    public static function score(Article $a, ArticleVersion $v, ?ContentBrief $brief, ?WritingPreset $preset, ?array $duplicates, array $consistency = []): array
    {
        $html = $v->content_html;
        $text = HtmlSanitizer::text($html);
        $lower = mb_strtolower($text);
        $words = max(1, HtmlSanitizer::wordCount($html));
        $claims = ArticleClaim::query()->where('article_version_id', $v->id)->get(['status', 'risk_level']);
        $issues = [];
        $c = [];

        // 1. Factual accuracy (20)
        $weights = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];
        $total = $claims->sum(fn ($x) => $weights[$x->risk_level] ?? 1);
        $ok = $claims->where('status', 'VERIFIED')->sum(fn ($x) => $weights[$x->risk_level] ?? 1);
        $contradicted = $claims->whereIn('status', ['CONTRADICTED', 'OUTDATED'])->count();
        $acc = $total > 0 ? 20 * $ok / $total : 20;
        $acc = max(0, $acc - 5 * $contradicted);
        $c[] = self::c('factual_accuracy', $acc, 20, [$claims->count().' claim, '.$claims->where('status', 'VERIFIED')->count().' VERIFIED, '.$contradicted.' sai/lỗi thời']);
        if ($claims->where('risk_level', 'CRITICAL')->where('status', '!=', 'VERIFIED')->count() > 0) {
            $issues[] = ['severity' => 'error', 'message' => 'Còn claim CRITICAL chưa được xác minh — chặn duyệt.'];
        }

        // 2. Evidence coverage (10)
        $needing = $claims->whereIn('risk_level', ['MEDIUM', 'HIGH', 'CRITICAL'])->count();
        $withEvidence = $claims->whereIn('risk_level', ['MEDIUM', 'HIGH', 'CRITICAL'])->whereIn('status', ['VERIFIED', 'CONTRADICTED', 'OUTDATED'])->count();
        $c[] = self::c('evidence_coverage', $needing > 0 ? 10 * $withEvidence / $needing : 10, 10, ["{$withEvidence}/{$needing} claim quan trọng có bằng chứng"]);

        // 3. Search intent alignment (10)
        $kw = mb_strtolower($a->primary_keyword);
        $h1 = mb_strtolower((string) ($v->title ?? ''));
        $first = mb_substr($lower, 0, 600);
        $intent = 0;
        $n = [];
        $intent += str_contains($h1, $kw) ? 3 : 0;
        $intent += str_contains($first, $kw) ? 3 : 0;
        $mq = mb_strtolower((string) ($brief?->data['main_question'] ?? ''));
        $mqTokens = array_filter(preg_split('/\s+/u', $mq) ?: [], fn ($t) => mb_strlen($t) > 3);
        $hit = $mqTokens === [] ? 1.0 : count(array_filter($mqTokens, fn ($t) => str_contains($first, $t))) / count($mqTokens);
        $intent += 4 * min(1, $hit * 1.5);
        if (! str_contains($h1, $kw)) {
            $n[] = 'Tiêu đề chưa chứa từ khóa chính';
        }
        if (! str_contains($first, $kw)) {
            $n[] = 'Đoạn mở đầu chưa nhắc từ khóa chính';
        }
        $c[] = self::c('search_intent', $intent, 10, $n);

        // 4. Structure & heading hierarchy (10)
        preg_match_all('#<h([1-6])\b#i', $html, $hm);
        $levels = array_map('intval', $hm[1] ?? []);
        $s = 10;
        $n = [];
        if (count(array_filter($levels, fn ($l) => $l === 1)) !== 1) {
            $s -= 3;
            $n[] = 'Cần đúng một H1';
        }
        $prev = 1;
        foreach ($levels as $l) {
            if ($l > $prev + 1) {
                $s -= 2;
                $n[] = 'Nhảy cấp heading (H'.$prev.' → H'.$l.')';
                break;
            }
            $prev = $l;
        }
        $h2 = count(array_filter($levels, fn ($l) => $l === 2));
        if ($h2 < 2 && $words > 500) {
            $s -= 3;
            $n[] = 'Quá ít H2 cho độ dài bài';
        }
        $c[] = self::c('structure', $s, 10, $n);

        // 5. Keyword usage (8)
        $count = $kw !== '' ? mb_substr_count($lower, $kw) : 0;
        $kwWords = max(1, count(preg_split('/\s+/u', $kw) ?: []));
        $density = 100 * $count * $kwWords / $words;
        $s = 8;
        $n = [sprintf('Mật độ từ khóa %.1f%%', $density)];
        if ($density > 3) {
            $s = 2;
            $n[] = 'Nhồi từ khóa';
            $issues[] = ['severity' => 'warning', 'message' => 'Mật độ từ khóa quá cao (nhồi từ khóa).'];
        } elseif ($count === 0) {
            $s = 2;
        } elseif ($density < 0.3) {
            $s = 5;
        }
        $c[] = self::c('keyword_usage', $s, 8, $n);

        // 6. Readability (8)
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $avg = count($sentences) > 0 ? $words / count($sentences) : 0;
        preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $pm);
        $long = count(array_filter($pm[1] ?? [], fn ($p) => HtmlSanitizer::wordCount($p) > 120));
        $s = 8 - ($avg > 30 ? 3 : ($avg > 25 ? 1.5 : 0)) - min(3, $long);
        if (in_array($a->content_type, ['how_to', 'troubleshooting', 'buying_guide'], true) && ! preg_match('#<(ol|ul)\b#i', $html)) {
            $s -= 2;
        }
        $c[] = self::c('readability', $s, 8, [sprintf('Trung bình %.0f từ/câu', $avg), $long > 0 ? "{$long} đoạn quá dài" : 'Độ dài đoạn ổn']);

        // 7. Originality / duplicate risk (8)
        $maxSim = 0.0;
        foreach (($duplicates['matches'] ?? []) as $m) {
            $maxSim = max($maxSim, (float) ($m['content_similarity'] ?? 0), (float) ($m['title_similarity'] ?? 0) * 0.5);
        }
        $c[] = self::c('originality', 8 * (1 - min(1, $maxSim * 1.5)), 8, [sprintf('Độ tương đồng cao nhất %.0f%%', $maxSim * 100)]);
        if (($duplicates['recommendation'] ?? 'create_new') !== 'create_new') {
            $issues[] = ['severity' => 'warning', 'message' => 'Rủi ro trùng lặp/cannibalization: '.($duplicates['reason'] ?? '')];
        }

        // 8. Repetition & boilerplate (6)
        $units = array_map(fn ($x) => trim(mb_strtolower($x)), $sentences);
        $repeated = count($units) - count(array_unique($units));
        $boiler = array_values(array_filter(self::BOILERPLATE, fn ($p) => str_contains($lower, $p)));
        $c[] = self::c('repetition', 6 - min(3, $repeated) - min(3, count($boiler)), 6,
            array_filter([$repeated > 0 ? "{$repeated} câu lặp lại" : null, $boiler !== [] ? 'Câu sáo rỗng: '.implode(', ', $boiler) : null]));

        // 9. Internal links (5)
        $internal = preg_match_all('#<a\b[^>]*data-internal="1"#i', $html);
        $c[] = self::c('internal_linking', min(5, $internal * 2.5), 5, ["{$internal} liên kết nội bộ"]);

        // 10. External citations (5)
        $cites = preg_match_all('#data-evidence-id=#i', $html);
        $c[] = self::c('citations', $claims->count() === 0 ? 5 : min(5, $cites * 1.25), 5, ["{$cites} trích dẫn nguồn"]);

        // 11. Brand voice (5)
        $forbidden = array_values(array_filter($preset->forbidden_phrases ?? [], fn ($p) => is_string($p) && $p !== '' && str_contains($lower, mb_strtolower($p))));
        $preferred = array_filter($preset->preferred_terms ?? [], 'is_string');
        $usedPreferred = count(array_filter($preferred, fn ($p) => str_contains($lower, mb_strtolower($p))));
        $c[] = self::c('brand_voice', 5 - min(5, 2 * count($forbidden)) - ($preferred !== [] && $usedPreferred === 0 ? 1 : 0), 5,
            array_filter([$forbidden !== [] ? 'Có cụm từ bị cấm: '.implode(', ', $forbidden) : null]));
        if ($forbidden !== []) {
            $issues[] = ['severity' => 'error', 'message' => 'Bài viết chứa cụm từ bị cấm theo Writing Preset: '.implode(', ', $forbidden)];
        }

        // 12. CTA relevance (3)
        $last = mb_substr($lower, -800);
        $hasCta = preg_match('/(liên hệ|đăng ký|mua ngay|xem thêm|tham khảo|đặt hàng|nhận tư vấn|tải về|khám phá)/u', $last) === 1 || preg_match('#class="cta"#', $html) === 1;
        $c[] = self::c('cta', $hasCta ? 3 : ($a->setting('cta') ? 0 : 2), 3, [$hasCta ? 'Có CTA cuối bài' : 'Chưa có CTA']);

        // 13. Editorial completeness (2)
        $faqWanted = ($brief?->data['faq'] ?? []) !== [];
        $hasFaq = preg_match('/(câu hỏi thường gặp|faq)/u', $lower) === 1;
        $hasConclusion = preg_match('/(kết luận|tổng kết|lời kết)/u', $lower) === 1;
        $c[] = self::c('completeness', ($hasConclusion ? 1 : 0) + (! $faqWanted || $hasFaq ? 1 : 0), 2,
            array_filter([$hasConclusion ? null : 'Thiếu kết luận', $faqWanted && ! $hasFaq ? 'Brief yêu cầu FAQ' : null]));

        foreach ($consistency as $ci) {
            $issues[] = ['severity' => $ci['severity'] === 'info' ? 'info' : 'warning', 'message' => $ci['message']];
        }
        $ph = PlaceholderDetector::scan($html);
        if ($ph['hollow']) {
            $issues[] = ['severity' => 'error', 'message' => 'Bài có nội dung giữ chỗ thay cho thông tin thật ('.($ph['cells'] > 0 ? $ph['cells'].' ô bảng, ' : '')
                .implode('; ', array_slice($ph['phrases'], 0, 3)).') — chặn duyệt.'];
        }
        $unsupported = $claims->whereIn('status', ['INSUFFICIENT_EVIDENCE', 'PENDING', 'NEEDS_HUMAN_REVIEW'])->count();
        if ($unsupported > 0) {
            $issues[] = ['severity' => 'warning', 'message' => "{$unsupported} khẳng định chưa được bằng chứng hỗ trợ (được đánh dấu trong báo cáo kiểm chứng)."];
        }

        $score = (int) round(array_sum(array_column($c, 'score')));

        return ['score' => max(0, min(100, $score)), 'criteria' => $c, 'issues' => $issues];
    }

    /**
     * @param  array<int, string|null>  $notes
     * @return array{key: string, score: float, max: int, notes: list<string>}
     */
    private static function c(string $key, float|int $score, int $max, array $notes): array
    {
        return ['key' => $key, 'score' => round(max(0, min($max, (float) $score)), 1), 'max' => $max, 'notes' => array_values(array_filter($notes))];
    }
}
