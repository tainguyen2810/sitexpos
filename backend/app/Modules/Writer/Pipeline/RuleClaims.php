<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Pipeline;

use SiteXPost\Modules\Writer\Enums\RiskLevel;
use SiteXPost\Modules\Writer\Support\Identifiers;

/**
 * Deterministic claim finder (safety net under the AI extractor) and the
 * risk floor per category: a model can raise a claim's risk, never lower it
 * below the floor.
 */
final class RuleClaims
{
    public const FLOOR = [
        'legal' => 'CRITICAL', 'risky_instruction' => 'CRITICAL',
        'spec' => 'HIGH', 'price' => 'HIGH', 'compatibility' => 'HIGH', 'version' => 'HIGH', 'release_date' => 'HIGH',
        'statistic' => 'MEDIUM', 'research' => 'MEDIUM', 'comparison' => 'MEDIUM',
        'other' => 'LOW',
    ];

    public static function risk(string $category, ?string $proposed): RiskLevel
    {
        $floor = RiskLevel::from(self::FLOOR[$category] ?? 'LOW');
        $p = RiskLevel::tryFrom((string) $proposed) ?? RiskLevel::Low;

        return RiskLevel::max($floor, $p);
    }

    /**
     * @return list<array{quote: string, claim: string, category: string, risk: string, evidence_requirements: string}>
     */
    public static function extract(string $text): array
    {
        $out = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $sentence) {
            $s = trim($sentence);
            if (mb_strlen($s) < 15 || mb_strlen($s) > 600 || str_ends_with($s, '?')) {
                continue;
            }
            $category = self::category($s);
            if ($category === null) {
                continue;
            }
            $out[] = ['quote' => $s, 'claim' => $s, 'category' => $category, 'risk' => self::FLOOR[$category],
                'evidence_requirements' => self::requirement($category)];
        }

        return $out;
    }

    public static function category(string $s): ?string
    {
        $l = mb_strtolower($s);
        if (preg_match('/(luật|nghị định|thông tư|quy định|bắt buộc theo|phạt|xử phạt|giấy phép)/u', $l) === 1) {
            return 'legal';
        }
        if (preg_match('/(điện áp cao|tháo pin|flash bios|cập nhật bios|liều|dòng điện|chập điện|nguy hiểm|cháy nổ)/u', $l) === 1) {
            return 'risky_instruction';
        }
        $ids = Identifiers::of($s);
        if (preg_match('/(\d[\d.,]*\s?(đ|đồng|vnđ|vnd|usd|\$|triệu|nghìn))|giá (bán|niêm yết)/u', $l) === 1) {
            return 'price';
        }
        if (preg_match('/(tương thích|hỗ trợ|không hỗ trợ|chạy được trên|dùng được với|compatible)/u', $l) === 1 && $ids !== []) {
            return 'compatibility';
        }
        if (preg_match('/(ra mắt|phát hành|công bố|release)/u', $l) === 1 && preg_match('/\b(19|20)\d{2}\b/', $l) === 1) {
            return 'release_date';
        }
        if (preg_match('/(phiên bản|version|firmware|driver)\s?v?\d/u', $l) === 1) {
            return 'version';
        }
        if (preg_match('/\d+(?:[.,]\d+)?\s?%|nghiên cứu|khảo sát|thống kê/u', $l) === 1) {
            return preg_match('/nghiên cứu/u', $l) === 1 ? 'research' : 'statistic';
        }
        if (preg_match('/(nhanh hơn|chậm hơn|tốt hơn|cao hơn|thấp hơn|gấp \d)/u', $l) === 1 && $ids !== []) {
            return 'comparison';
        }
        foreach ($ids as $id) {
            if (preg_match('/^\d/', $id) === 1 || Identifiers::isModelCode($id)) {
                return 'spec';
            }
        }

        return null;
    }

    public static function requirement(string $category): string
    {
        return match ($category) {
            'spec' => 'Tài liệu kỹ thuật / trang thông số chính thức đúng model',
            'price' => 'Nguồn giá có ngày cập nhật',
            'compatibility' => 'Tài liệu tương thích chính thức, đúng phiên bản',
            'version', 'release_date' => 'Thông báo / tài liệu chính thức có ngày',
            'legal' => 'Văn bản pháp luật gốc, còn hiệu lực',
            'risky_instruction' => 'Hướng dẫn chính thức của nhà sản xuất',
            'statistic', 'research' => 'Nghiên cứu / dữ liệu có phương pháp rõ ràng',
            'comparison' => 'Số liệu so sánh từ nguồn đáng tin cậy cho cả hai đối tượng',
            default => 'Nguồn đáng tin cậy',
        };
    }
}
