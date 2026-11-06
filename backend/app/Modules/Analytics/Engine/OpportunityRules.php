<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

use SiteXPost\Modules\Analytics\Support\UrlNormalizer;

/**
 * SEO Opportunity Detector rules v1 (SEO-OPPORTUNITY-RULES.md). Pure
 * functions over a prepared site dataset; every candidate carries its
 * evidence, the rule that fired, severity, a QUALITATIVE impact estimate
 * with its basis (never a traffic forecast or search volume), a
 * confidence score with explanation and the recommended action.
 */
final class OpportunityRules
{
    public const VERSION = 'v1';

    public const RULES = [
        'query_high_impr_low_ctr', 'page_traffic_decline', 'page_position_improving', 'new_query', 'content_missing_info',
        'outdated_info', 'keyword_cannibalization', 'internal_link_opportunity', 'orphan_content', 'broken_internal_links',
        'duplicate_title_meta', 'missing_metadata', 'content_gap', 'cluster_coverage_gap', 'refresh_candidate',
    ];

    /**
     * @param  array<string, mixed>  $d  dataset (see SiteDataset::build)
     * @param  array<string, float|int>  $t
     * @return list<array<string, mixed>>
     */
    public static function detect(array $d, array $t): array
    {
        $out = [];
        foreach (self::RULES as $rule) {
            $method = lcfirst(str_replace('_', '', ucwords($rule, '_')));
            foreach (self::$method($d, $t) as $c) {
                $out[] = $c + ['rule_code' => $rule, 'rule_version' => self::VERSION,
                    'fingerprint' => sha1($rule.'|'.$c['target_type'].'|'.$c['target_ref'])];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function queryHighImprLowCtr(array $d, array $t): array
    {
        $out = [];
        foreach ($d['queries'] as $q) {
            $r = $q['recent'];
            $ctr = Metrics::ctr($r['clicks'], $r['impressions']);
            if ($r['impressions'] < (int) $t['opp_min_impressions'] || $r['position'] === null || $r['position'] > 10 || $ctr === null) {
                continue;
            }
            $median = $d['band_medians'][Metrics::positionBand($r['position'])] ?? null;
            $limit = $median !== null ? $median * (float) $t['low_ctr_ratio'] : (float) $t['low_ctr_fallback_pct'];
            if ($ctr >= $limit) {
                continue;
            }
            $share = self::share($r['impressions'], $d['total_impressions']);
            $out[] = self::c('query', $q['query_hash'], $q['query'], null,
                ['window_days' => $d['window_days'], 'impressions' => $r['impressions'], 'clicks' => $r['clicks'], 'ctr_pct' => $ctr, 'position' => $r['position'],
                    'band_median_ctr_pct' => $median, 'top_page' => $q['top_page'] ?? null],
                'Truy vấn ở top 10, impressions ≥ '.$t['opp_min_impressions'].' và CTR < '.($median !== null ? ((float) $t['low_ctr_ratio'] * 100).'% trung vị CTR nội bộ cùng nhóm vị trí' : $t['low_ctr_fallback_pct'].'%'),
                $r['impressions'] >= (int) $t['high_impressions'] * 4 ? 'high' : 'medium',
                $share >= 5 ? 'high' : ($share >= 1 ? 'medium' : 'low'), "Truy vấn chiếm {$share}% lượt hiển thị của website trong kỳ.",
                $median !== null ? 70 : 50, $median !== null ? 'So với CTR của chính website ở cùng nhóm vị trí; dữ liệu GSC có thể ẩn một phần truy vấn.' : 'Chưa đủ trang cùng nhóm vị trí để so sánh nội bộ, dùng ngưỡng tuyệt đối.',
                'rewrite_title_meta', isset($q['top_page']['article_id']) ? $q['top_page']['article_id'] : null);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function pageTrafficDecline(array $d, array $t): array
    {
        $out = [];
        foreach ($d['pages'] as $p) {
            if ($p['classification'] !== TrendClassifier::DECLINING) {
                continue;
            }
            $out[] = self::c('page', $p['page_hash'], $p['title'] ?? $p['page_url'], $p['article_id'],
                ['page_url' => $p['page_url'], 'recent' => $p['recent'], 'previous' => $p['previous'], 'clicks_change_pct' => $p['clicks_change'], 'impressions_change_pct' => $p['impressions_change']],
                'Clicks giảm ≥ '.abs((float) $t['declining_pct']).'% và impressions giảm so với kỳ '.$d['window_days'].' ngày trước',
                ($p['clicks_change'] ?? 0) <= -50 ? 'high' : 'medium', self::impactFromClicks($p['previous']['clicks'], $d['total_clicks_prev']),
                'Trang chiếm '.self::share($p['previous']['clicks'], $d['total_clicks_prev']).'% clicks của website ở kỳ trước.',
                65, 'Hai chỉ số (clicks, impressions) cùng giảm; chưa xác định nguyên nhân.', 'investigate', $p['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function pagePositionImproving(array $d, array $t): array
    {
        $out = [];
        foreach ($d['pages'] as $p) {
            $r = $p['recent'];
            $prev = $p['previous'];
            if ($r['position'] === null || $prev['position'] === null || $r['impressions'] < (int) $t['min_impressions'] || $r['position'] > 20) {
                continue;
            }
            $gain = round($prev['position'] - $r['position'], 1);
            if ($gain < (float) $t['improving_positions'] || $r['impressions'] < $prev['impressions'] * 0.8) {
                continue;
            }
            $out[] = self::c('page', $p['page_hash'], $p['title'] ?? $p['page_url'], $p['article_id'],
                ['page_url' => $p['page_url'], 'position_now' => $r['position'], 'position_before' => $prev['position'], 'impressions' => $r['impressions']],
                'Vị trí trung bình cải thiện ≥ '.$t['improving_positions'].' bậc, đang trong top 20, impressions không giảm',
                'low', 'medium', 'Trang đang đi lên; củng cố (liên kết nội bộ, cấu trúc) có thể giữ đà — đây là giả thuyết.',
                55, 'Vị trí trung bình kèm điều kiện impressions; vẫn có thể dao động.', 'improve_article_structure', $p['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function newQuery(array $d, array $t): array
    {
        if (! ($d['has_long_history'] ?? false)) {
            return [];
        }
        $out = [];
        foreach ($d['queries'] as $q) {
            if ($q['recent']['impressions'] < (int) $t['new_query_min_impressions'] || $q['previous']['impressions'] > 0 || ($q['prior_impressions'] ?? 0) > 0) {
                continue;
            }
            $out[] = self::c('query', $q['query_hash'], $q['query'], null,
                ['impressions' => $q['recent']['impressions'], 'position' => $q['recent']['position'], 'first_seen_window' => $d['period']['recent']],
                'Truy vấn có ≥ '.$t['new_query_min_impressions'].' impressions trong kỳ gần nhất và 0 impressions ở các kỳ trước',
                'low', 'unknown', 'Truy vấn mới xuất hiện; chưa có lịch sử để ước lượng tác động.',
                45, 'Có thể là xu hướng ngắn hạn; cần theo dõi thêm.', 'create_supporting_article');
        }

        return array_slice($out, 0, 50);
    }

    /** @return list<array<string, mixed>> */
    private static function contentMissingInfo(array $d, array $t): array
    {
        $out = [];
        $byPage = [];
        foreach ($d['query_pages'] as $qp) {
            $byPage[$qp['page_hash']][] = $qp;
        }
        foreach ($d['managed'] as $a) {
            if ($a['page_hash'] === null || ! isset($byPage[$a['page_hash']]) || $a['text'] === '') {
                continue;
            }
            $missing = [];
            foreach ($byPage[$a['page_hash']] as $qp) {
                if ($qp['impressions'] < 50) {
                    continue;
                }
                $words = array_filter(preg_split('/\s+/u', mb_strtolower($qp['query'])) ?: [], fn ($w) => mb_strlen($w) >= 3);
                if ($words === []) {
                    continue;
                }
                $found = count(array_filter($words, fn ($w) => str_contains($a['text'], $w)));
                if ($found / count($words) < 0.5) {
                    $missing[] = ['query' => $qp['query'], 'impressions' => $qp['impressions'], 'position' => $qp['position']];
                }
            }
            if ($missing === []) {
                continue;
            }
            usort($missing, fn ($x, $y) => $y['impressions'] <=> $x['impressions']);
            $out[] = self::c('article', $a['article_id'], $a['title'], $a['article_id'],
                ['page_url' => $a['url'], 'uncovered_queries' => array_slice($missing, 0, 10)],
                'Bài nhận impressions cho truy vấn mà nội dung hiện tại không chứa ≥ 50% từ khóa của truy vấn',
                count($missing) >= 5 ? 'medium' : 'low', 'medium', 'Người tìm kiếm thấy bài cho '.count($missing).' truy vấn mà bài chưa trả lời rõ.',
                40, 'So khớp từ ngữ đơn giản, không hiểu ngữ nghĩa — cần người duyệt xác nhận.', 'add_missing_sections', $a['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function outdatedInfo(array $d, array $t): array
    {
        $out = [];
        $limitYear = (int) $d['current_year'] - (int) $t['outdated_years'];
        foreach ($d['managed'] as $a) {
            if (($a['days_since_update'] ?? 0) < (int) $t['refresh_age_days'] || $a['text'] === '') {
                continue;
            }
            preg_match_all('/\b(19|20)\d{2}\b/u', $a['text'], $m);
            $years = array_values(array_unique(array_filter(array_map('intval', $m[0]), fn ($y) => $y <= $limitYear && $y >= 1990)));
            if ($years === []) {
                continue;
            }
            sort($years);
            $out[] = self::c('article', $a['article_id'], $a['title'], $a['article_id'],
                ['page_url' => $a['url'], 'years_mentioned' => array_slice($years, -5), 'days_since_update' => $a['days_since_update']],
                'Bài chưa cập nhật ≥ '.$t['refresh_age_days'].' ngày và nhắc tới năm ≤ '.$limitYear,
                'low', 'unknown', 'Thông tin có mốc thời gian cũ có thể đã lỗi thời; cần kiểm chứng lại.',
                35, 'Chỉ dựa trên mốc năm trong văn bản; năm cũ có thể là dữ kiện lịch sử đúng.', 'update_outdated_facts', $a['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function keywordCannibalization(array $d, array $t): array
    {
        $byQuery = [];
        foreach ($d['query_pages'] as $qp) {
            $byQuery[$qp['query_hash']][] = $qp;
        }
        $out = [];
        foreach ($byQuery as $hash => $rows) {
            $total = array_sum(array_column($rows, 'impressions'));
            if ($total < (int) $t['cannibal_min_impressions'] || count($rows) < 2) {
                continue;
            }
            $competing = array_values(array_filter($rows, fn ($r) => (float) $t['cannibal_min_share'] <= $r['impressions'] / $total));
            if (count($competing) < 2) {
                continue;
            }
            usort($competing, fn ($x, $y) => $y['impressions'] <=> $x['impressions']);
            $out[] = self::c('query', $hash, $competing[0]['query'], $competing[0]['article_id'] ?? null,
                ['total_impressions' => $total, 'pages' => array_map(fn ($r) => ['page_url' => $r['page_url'], 'impressions' => $r['impressions'], 'clicks' => $r['clicks'], 'position' => $r['position'],
                    'share_pct' => round($r['impressions'] / $total * 100, 1)], array_slice($competing, 0, 5))],
                '≥ 2 URL của website cùng nhận ≥ '.((float) $t['cannibal_min_share'] * 100).'% impressions cho một truy vấn',
                'medium', 'medium', 'Nhiều URL cạnh tranh cùng truy vấn có thể chia tín hiệu xếp hạng.',
                50, 'Ứng viên cannibalization — có thể là chủ ý (ví dụ trang danh mục và bài viết). Cần người xem xét.', 'merge_overlapping_articles');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function internalLinkOpportunity(array $d, array $t): array
    {
        $out = [];
        foreach ($d['managed'] as $target) {
            $snap = $target['page_hash'] !== null ? ($d['pages_by_hash'][$target['page_hash']] ?? null) : null;
            if ($snap === null || ! in_array('near_top', $snap['labels'], true) || $target['primary_keyword'] === '') {
                continue;
            }
            $kw = mb_strtolower($target['primary_keyword']);
            $sources = [];
            foreach ($d['managed'] as $src) {
                if ($src['article_id'] === $target['article_id'] || ! str_contains($src['text'], $kw)) {
                    continue;
                }
                if ($target['url'] !== null && in_array(UrlNormalizer::normalize($target['url']), $src['links_norm'], true)) {
                    continue;
                }
                $sources[] = ['article_id' => $src['article_id'], 'title' => $src['title']];
            }
            if ($sources === []) {
                continue;
            }
            $out[] = self::c('article', $target['article_id'], $target['title'], $target['article_id'],
                ['page_url' => $target['url'], 'position' => $snap['recent']['position'], 'keyword' => $target['primary_keyword'], 'candidate_sources' => array_slice($sources, 0, 5)],
                'Bài gần top (vị trí '.$t['near_top_min_position'].'–'.$t['near_top_max_position'].') và có bài khác nhắc tới từ khóa chính nhưng chưa liên kết',
                'low', 'medium', count($sources).' bài liên quan có thể liên kết tới bài này.',
                55, 'Chỉ xét bài do SiteX quản lý (chưa có crawler toàn site).', 'improve_internal_links', $target['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function orphanContent(array $d, array $t): array
    {
        $managed = array_values(array_filter($d['managed'], fn ($a) => $a['url'] !== null));
        if (count($managed) < 3) {
            return [];
        }
        $linked = [];
        foreach ($managed as $a) {
            foreach ($a['links_norm'] as $l) {
                $linked[$l] = true;
            }
        }
        $out = [];
        foreach ($managed as $a) {
            if (isset($linked[UrlNormalizer::normalize((string) $a['url'])])) {
                continue;
            }
            $out[] = self::c('article', $a['article_id'], $a['title'], $a['article_id'], ['page_url' => $a['url'], 'checked_articles' => count($managed)],
                'Không bài nào khác (trong '.count($managed).' bài do SiteX quản lý) liên kết tới bài này',
                'low', 'low', 'Bài mồ côi khó được người đọc và công cụ tìm kiếm khám phá.',
                45, 'Chỉ tính liên kết trong nội dung SiteX quản lý; menu/sidebar WordPress chưa được thu thập.', 'improve_internal_links', $a['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function brokenInternalLinks(array $d, array $t): array
    {
        if ($d['inventory_urls'] === []) {
            return [];
        }
        $known = array_flip($d['inventory_urls']);
        $out = [];
        foreach ($d['managed'] as $a) {
            $broken = [];
            foreach ($a['links_norm'] as $l) {
                if (UrlNormalizer::host($l) === $d['host'] && ! isset($known[$l]) && preg_match('#/(wp-content|wp-admin|feed|tag|category|page)/#', $l) !== 1 && parse_url($l, PHP_URL_PATH) !== '/') {
                    $broken[] = $l;
                }
            }
            if ($broken === []) {
                continue;
            }
            $out[] = self::c('article', $a['article_id'], $a['title'], $a['article_id'], ['page_url' => $a['url'], 'links' => array_slice($broken, 0, 20)],
                'Liên kết nội bộ trỏ tới URL không có trong danh mục nội dung đã đồng bộ từ WordPress',
                count($broken) >= 3 ? 'medium' : 'low', 'low', 'Liên kết hỏng làm giảm trải nghiệm người đọc.',
                60, 'Đối chiếu với danh mục đã đồng bộ; URL mới tạo sau lần đồng bộ cuối có thể bị báo nhầm.', 'fix_broken_links', $a['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function duplicateTitleMeta(array $d, array $t): array
    {
        $groups = [];
        foreach ($d['inventory_titles'] as $row) {
            $key = mb_strtolower(trim((string) $row['title']));
            if ($key !== '') {
                $groups['title:'.$key][] = $row['url'];
            }
        }
        foreach ($d['managed'] as $a) {
            foreach (['seo_title', 'meta_description'] as $f) {
                $v = mb_strtolower(trim((string) ($a['metadata'][$f] ?? '')));
                if ($v !== '') {
                    $groups[$f.':'.$v][] = $a['url'] ?? $a['article_id'];
                }
            }
        }
        $out = [];
        foreach ($groups as $key => $urls) {
            $urls = array_values(array_unique(array_filter($urls)));
            if (count($urls) < 2) {
                continue;
            }
            [$field, $value] = explode(':', $key, 2);
            $out[] = self::c('site', sha1($key), mb_substr($value, 0, 200), null, ['field' => $field, 'value' => mb_substr($value, 0, 300), 'urls' => array_slice($urls, 0, 10)],
                'Cùng một '.$field.' xuất hiện ở ≥ 2 URL', 'low', 'low', 'Tiêu đề/mô tả trùng làm khó phân biệt các trang trong kết quả tìm kiếm.',
                80, 'So khớp chính xác (không phân biệt hoa thường).', 'fix_metadata');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function missingMetadata(array $d, array $t): array
    {
        $out = [];
        foreach ($d['managed'] as $a) {
            if ($a['metadata'] === null) {
                continue;
            }
            $missing = array_values(array_filter(['seo_title', 'meta_description'], fn ($f) => trim((string) ($a['metadata'][$f] ?? '')) === ''));
            if ($missing === []) {
                continue;
            }
            $out[] = self::c('article', $a['article_id'], $a['title'], $a['article_id'], ['page_url' => $a['url'], 'missing' => $missing],
                'Bài đã xuất bản thiếu '.implode(', ', $missing), 'medium', 'low', 'Thiếu metadata khiến Google tự chọn đoạn hiển thị.',
                90, 'Đọc trực tiếp từ metadata SEO của bài.', 'fix_metadata', $a['article_id']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function contentGap(array $d, array $t): array
    {
        $out = [];
        foreach ($d['queries'] as $q) {
            $r = $q['recent'];
            if ($r['impressions'] < (int) $t['gap_min_impressions'] || $r['position'] === null || $r['position'] < (float) $t['gap_min_position']) {
                continue;
            }
            $out[] = self::c('query', $q['query_hash'], $q['query'], null, ['impressions' => $r['impressions'], 'position' => $r['position'], 'top_page' => $q['top_page'] ?? null],
                'Website có impressions ≥ '.$t['gap_min_impressions'].' cho truy vấn nhưng vị trí trung bình > '.$t['gap_min_position'],
                'low', 'unknown', 'Có nhu cầu tìm kiếm mà website hiện chưa có nội dung phù hợp (dựa trên dữ liệu GSC của chính website).',
                40, 'Vị trí thấp có thể do nội dung chưa phù hợp hoặc cạnh tranh cao.', 'create_supporting_article');
        }

        return array_slice($out, 0, 50);
    }

    /** @return list<array<string, mixed>> */
    private static function clusterCoverageGap(array $d, array $t): array
    {
        $out = [];
        foreach ($d['clusters'] as $c) {
            if ($c['ideas'] < 3 || $c['published'] / max(1, $c['ideas']) >= 0.5) {
                continue;
            }
            $out[] = self::c('cluster', sha1('cluster:'.$c['name']), $c['name'], null, ['ideas' => $c['ideas'], 'published' => $c['published']],
                'Cụm chủ đề có ≥ 3 ý tưởng nhưng < 50% đã có bài xuất bản', 'low', 'medium', "Cụm mới phủ {$c['published']}/{$c['ideas']} chủ đề đã lập kế hoạch.",
                60, 'Dựa trên kế hoạch nội dung (Phase 4) và bài đã xuất bản.', 'expand_topic_cluster');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function refreshCandidate(array $d, array $t): array
    {
        $out = [];
        foreach ($d['pages'] as $p) {
            if (! in_array('needs_refresh', $p['labels'], true)) {
                continue;
            }
            $out[] = self::c('page', $p['page_hash'], $p['title'] ?? $p['page_url'], $p['article_id'], ['page_url' => $p['page_url'], 'recent' => $p['recent'], 'previous' => $p['previous'], 'reasons' => $p['reasons']],
                'Nội dung chưa cập nhật ≥ '.$t['refresh_age_days'].' ngày và đang suy giảm', 'medium', self::impactFromClicks($p['previous']['clicks'], $d['total_clicks_prev']),
                'Trang chiếm '.self::share($p['previous']['clicks'], $d['total_clicks_prev']).'% clicks của website ở kỳ trước.',
                55, 'Kết hợp tuổi nội dung và xu hướng giảm; nguyên nhân chưa được xác định.', 'refresh_content', $p['article_id']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private static function c(string $type, string $ref, ?string $label, ?string $articleId, array $evidence, string $rule, string $severity, string $impact, string $basis, int $confidence, string $why, string $action, ?string $articleOverride = null): array
    {
        return ['target_type' => $type, 'target_ref' => mb_substr($ref, 0, 1000), 'target_label' => $label !== null ? mb_substr($label, 0, 500) : null,
            'article_id' => $articleOverride ?? $articleId, 'evidence' => $evidence, 'detection_rule' => mb_substr($rule, 0, 500), 'severity' => $severity,
            'impact' => $impact, 'impact_basis' => mb_substr($basis, 0, 500), 'confidence' => max(0, min(100, $confidence)),
            'confidence_explanation' => mb_substr($why, 0, 500), 'recommended_action' => $action];
    }

    private static function share(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    private static function impactFromClicks(int $clicks, int $total): string
    {
        $s = self::share($clicks, $total);

        return $s >= 5 ? 'high' : ($s >= 1 ? 'medium' : 'low');
    }
}
