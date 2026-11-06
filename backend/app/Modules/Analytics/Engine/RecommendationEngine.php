<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Engine;

/**
 * AI SEO Recommendation Engine — rule layer. Turns an opportunity into a
 * recommendation with type, rationale, priority, a benefit HYPOTHESIS (no
 * ranking promise), cost estimate, risk and the approval it needs. AI may
 * later add a suggested value (e.g. a new title) but never changes these
 * fields or applies anything by itself.
 */
final class RecommendationEngine
{
    public const TYPES = [
        'rewrite_seo_title', 'improve_meta_description', 'add_missing_sections', 'update_outdated_facts', 'improve_internal_links',
        'merge_overlapping_articles', 'refresh_content', 'create_supporting_article', 'expand_topic_cluster', 'fix_broken_links', 'improve_article_structure',
    ];

    /** Types that change existing article content and therefore go through the Content Refresh pipeline. */
    public const REFRESH_TYPES = ['add_missing_sections', 'update_outdated_facts', 'refresh_content', 'improve_article_structure'];

    private const MAP = [
        'rewrite_title_meta' => ['rewrite_seo_title', 'low', 'editor', 'Tiêu đề/mô tả hấp dẫn hơn có thể cải thiện CTR ở vị trí hiện tại (giả thuyết, cần đo trước/sau).'],
        'fix_metadata' => ['improve_meta_description', 'low', 'editor', 'Metadata đầy đủ, không trùng giúp trang được hiển thị đúng ý định (giả thuyết).'],
        'add_missing_sections' => ['add_missing_sections', 'medium', 'editor', 'Bổ sung phần trả lời các truy vấn mà bài đang nhận hiển thị có thể giúp bài đáp ứng nhu cầu tốt hơn (giả thuyết).'],
        'update_outdated_facts' => ['update_outdated_facts', 'medium', 'editor', 'Cập nhật dữ kiện có mốc thời gian cũ giúp nội dung chính xác hơn; mọi dữ kiện mới phải qua fact-check.'],
        'improve_internal_links' => ['improve_internal_links', 'low', 'editor', 'Thêm liên kết nội bộ liên quan giúp người đọc và công cụ tìm kiếm khám phá bài (giả thuyết).'],
        'merge_overlapping_articles' => ['merge_overlapping_articles', 'high', 'owner', 'Gộp/phân biệt các bài trùng ý định có thể giảm cạnh tranh nội bộ; thao tác có rủi ro (redirect, mất nội dung).'],
        'refresh_content' => ['refresh_content', 'medium', 'editor', 'Làm mới nội dung đang suy giảm có thể giúp bài lấy lại hiệu quả; không bảo đảm thứ hạng.'],
        'investigate' => ['refresh_content', 'medium', 'editor', 'Nên kiểm tra nguyên nhân suy giảm trước; làm mới nội dung chỉ là một hướng xử lý.'],
        'create_supporting_article' => ['create_supporting_article', 'low', 'editor', 'Bài viết mới cho nhu cầu tìm kiếm website đang hiển thị nhưng chưa có nội dung phù hợp (giả thuyết).'],
        'expand_topic_cluster' => ['expand_topic_cluster', 'low', 'editor', 'Phủ thêm chủ đề đã lập kế hoạch giúp cụm nội dung hoàn chỉnh hơn (giả thuyết).'],
        'fix_broken_links' => ['fix_broken_links', 'low', 'editor', 'Sửa liên kết hỏng cải thiện trải nghiệm người đọc.'],
        'improve_article_structure' => ['improve_article_structure', 'low', 'editor', 'Củng cố cấu trúc bài đang đi lên có thể giúp giữ đà (giả thuyết).'],
    ];

    private const SEVERITY = ['critical' => 100, 'high' => 80, 'medium' => 55, 'low' => 30];

    /**
     * @param  array<string, mixed>  $opp  seo_opportunities row as array
     * @return array<string, mixed>|null
     */
    public static function fromOpportunity(array $opp, int $estimatedCost): ?array
    {
        $m = self::MAP[$opp['recommended_action']] ?? null;
        if ($m === null) {
            return null;
        }
        [$type, $risk, $approval, $hypothesis] = $m;
        $priority = (int) round((self::SEVERITY[$opp['severity']] ?? 30) * (0.5 + (int) $opp['confidence'] / 200));
        if ($type === 'rewrite_seo_title' && ($opp['evidence']['band_median_ctr_pct'] ?? null) === null) {
            $priority = max(1, $priority - 10);
        }

        return [
            'type' => $type, 'target_type' => $opp['target_type'], 'target_ref' => $opp['target_ref'], 'target_label' => $opp['target_label'],
            'article_id' => $opp['article_id'], 'evidence' => ['opportunity_rule' => $opp['rule_code'], 'detection_rule' => $opp['detection_rule']] + (array) $opp['evidence'],
            'rationale' => $opp['detection_rule'].'. '.$opp['impact_basis'].' Độ tin cậy '.$opp['confidence'].'/100: '.$opp['confidence_explanation'],
            'priority' => max(1, min(100, $priority)), 'benefit_hypothesis' => $hypothesis, 'estimated_cost_micro_usd' => $estimatedCost, 'risk' => $risk,
            'risk_notes' => $type === 'merge_overlapping_articles' ? 'Cần quyết định redirect 301 và nội dung giữ lại; SiteX không tự gộp bài.' : ($opp['article_id'] === null && in_array($type, self::REFRESH_TYPES, true) ? 'Trang không do SiteX quản lý — chỉ có thể làm thủ công.' : null),
            'required_approval' => $approval, 'fingerprint' => sha1($type.'|'.$opp['fingerprint']),
        ];
    }
}
