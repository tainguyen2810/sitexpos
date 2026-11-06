<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Analysis;

use SiteXPost\Modules\Research\Support\Text;

/**
 * Explainable rule-based search-intent classifier (Vietnamese + English).
 *
 * search intent: informational | commercial | transactional | navigational
 * content type:  informational | commercial | transactional | navigational |
 *                tutorial | comparison | troubleshooting | faq | pillar
 *
 * Rules are matched on the folded text (no diacritics) on word boundaries.
 * The matched rule is returned so the UI can show *why*.
 */
final class IntentClassifier
{
    public const INTENTS = ['informational', 'commercial', 'transactional', 'navigational'];

    public const CONTENT_TYPES = ['informational', 'commercial', 'transactional', 'navigational', 'tutorial', 'comparison', 'troubleshooting', 'faq', 'pillar'];

    /** @var array<string, list<string>> content type → folded phrases */
    private const TYPE_RULES = [
        'comparison' => ['so sanh', 'vs', 'versus', 'khac nhau', 'khac gi', 'hay la', 'compare', 'comparison', 'difference between', 'alternative', 'alternatives', 'thay the cho'],
        'troubleshooting' => ['loi', 'sua loi', 'khac phuc', 'khong nhan', 'khong ket noi', 'khong hoat dong', 'khong bat', 'bi cham', 'bi mat', 'bi rot', 'chap chon', 'fix', 'error', 'not working', 'problem', 'troubleshoot', 'issue', 'driver loi'],
        'tutorial' => ['cach', 'huong dan', 'how to', 'cai dat', 'thay', 'nang cap', 'lap dat', 'cau hinh', 'setup', 'install', 'upgrade', 'replace', 'tutorial', 'guide', 'step by step', 'tu lam', 'meo'],
        'faq' => ['la gi', 'tai sao', 'vi sao', 'co nen', 'bao nhieu', 'bao lau', 'co phai', 'nhu the nao', 'what is', 'why', 'is it', 'should i', 'how much', 'how long', 'can i'],
    ];

    private const COMMERCIAL = ['tot nhat', 'top', 'review', 'danh gia', 'nen mua', 'loai nao', 'hang nao', 'best', 'reviews', 'recommended', 'chon mua', 'kinh nghiem chon', 'uu nhuoc diem', 'pros and cons'];

    private const TRANSACTIONAL = ['mua', 'gia', 'bao gia', 'dat hang', 'giam gia', 'khuyen mai', 'ship', 'giao hang', 'cua hang', 'chinh hang', 'tra gop', 'price', 'buy', 'order', 'cheap', 'deal', 'coupon', 'shop', 'for sale', 'discount'];

    private const NAVIGATIONAL = ['dang nhap', 'login', 'trang chu', 'lien he', 'hotline', 'dia chi', 'website', 'official', 'chi nhanh', 'showroom'];

    /**
     * @param  list<string>  $brandTerms  campaign brand names (navigational)
     * @return array{intent: string, content_type: string, rule: string|null, confidence: int}
     */
    public function classify(string $keyword, array $brandTerms = []): array
    {
        $f = ' '.Text::fold($keyword).' ';
        $isQuestion = str_contains($keyword, '?');

        foreach ($brandTerms as $brand) {
            $b = Text::fold($brand);
            if ($b !== '' && str_contains($f, ' '.$b.' ') && (count(Text::tokens($keyword)) <= count(Text::tokens($brand)) + 1 || self::hit($f, self::NAVIGATIONAL) !== null)) {
                return ['intent' => 'navigational', 'content_type' => 'navigational', 'rule' => 'brand:'.$b, 'confidence' => 85];
            }
        }
        if (($rule = self::hit($f, self::NAVIGATIONAL)) !== null) {
            return ['intent' => 'navigational', 'content_type' => 'navigational', 'rule' => $rule, 'confidence' => 70];
        }

        // "đánh giá" is commercial, not a price query.
        $priceSafe = str_replace([' danh gia ', ' gia dinh ', ' gia dung ', ' tham gia ', ' gia tri '], ' ', $f);

        $type = null;
        $typeRule = null;
        foreach (self::TYPE_RULES as $candidate => $phrases) {
            if (($rule = self::hit($f, $phrases)) !== null) {
                $type = $candidate;
                $typeRule = $rule;
                break;
            }
        }
        if ($type === null && $isQuestion) {
            $type = 'faq';
            $typeRule = '?';
        }

        $commercial = self::hit($f, self::COMMERCIAL);
        $transactional = self::hit($priceSafe, self::TRANSACTIONAL);

        if ($type === 'comparison') {
            return ['intent' => 'commercial', 'content_type' => 'comparison', 'rule' => $typeRule, 'confidence' => 80];
        }
        if ($transactional !== null && $type === null) {
            return ['intent' => 'transactional', 'content_type' => 'transactional', 'rule' => $transactional, 'confidence' => 75];
        }
        if ($commercial !== null && $type === null) {
            return ['intent' => 'commercial', 'content_type' => 'commercial', 'rule' => $commercial, 'confidence' => 75];
        }
        if ($type !== null) {
            // "nên mua loại nào" → faq wording but commercial intent
            $intent = $commercial !== null || ($transactional !== null && $type === 'faq') ? 'commercial' : 'informational';

            return ['intent' => $intent, 'content_type' => $type, 'rule' => $typeRule, 'confidence' => 75];
        }

        // Bare head terms ("card wifi laptop") are ambiguous: informational
        // with low confidence; SERP-level intent needs AI or a SERP provider.
        return ['intent' => 'informational', 'content_type' => 'informational', 'rule' => null, 'confidence' => 40];
    }

    public static function family(string $contentType): string
    {
        return match ($contentType) {
            'comparison', 'commercial' => 'commercial',
            'transactional' => 'transactional',
            'navigational' => 'navigational',
            'troubleshooting' => 'troubleshooting',
            'tutorial' => 'tutorial',
            default => 'informational',
        };
    }

    /**
     * @param  list<string>  $phrases
     */
    private static function hit(string $folded, array $phrases): ?string
    {
        foreach ($phrases as $p) {
            if (str_contains($folded, ' '.$p.' ')) {
                return $p;
            }
        }

        return null;
    }
}
