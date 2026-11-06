<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Retrieval;

/**
 * Source type + reliability indicators (FACT-CHECK-POLICY.md §2). Rules
 * only — no model opinion. The score orders evidence; it is never evidence
 * itself.
 */
final class SourceClassifier
{
    /** type => [priority rank (1 = best), base score] */
    public const TYPES = [
        'manufacturer' => [1, 90],
        'technical_doc' => [2, 85],
        'regulator' => [3, 90],
        'research' => [4, 80],
        'reputable' => [5, 65],
        'reference' => [5, 60],
        'customer_site' => [6, 55],
        'other' => [7, 40],
    ];

    /**
     * @param  list<string>  $brandTokens  lowercase tokens from keywords / brand terms / products
     */
    public static function type(string $url, ?string $siteHost, array $brandTokens, ?string $declared = null): string
    {
        if ($declared !== null && isset(self::TYPES[$declared])) {
            return $declared;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($siteHost !== null && $host === preg_replace('/^www\./', '', strtolower($siteHost))) {
            return 'customer_site';
        }
        if (preg_match('/(\.gov(\.[a-z]{2})?$|\.gov\.vn$|\.mil$|(^|\.)who\.int$|(^|\.)europa\.eu$|chinhphu\.vn$|thuvienphapluat\.vn$)/', $host) === 1) {
            return 'regulator';
        }
        if (preg_match('/(\.edu(\.[a-z]{2})?$|\.ac\.[a-z]{2}$|(^|\.)arxiv\.org$|(^|\.)doi\.org$|(^|\.)ncbi\.nlm\.nih\.gov$|(^|\.)nature\.com$|(^|\.)ieee\.org$)/', $host) === 1) {
            return 'research';
        }
        if (preg_match('/(^|\.)(wikipedia|wikimedia)\.org$/', $host) === 1) {
            return 'reference';
        }
        $label = self::registrableLabel($host);
        $isBrand = $label !== '' && in_array($label, $brandTokens, true);
        $isDoc = preg_match('/^(docs|developer|developers|support|kb|manuals?|datasheets?|ark|learn)\./', $host) === 1
            || preg_match('#/(docs?|documentation|datasheets?|specs?|specifications|manuals?|support|kb|knowledge-?base|product-?specifications)(/|$)#', $path) === 1;
        if ($isBrand) {
            return 'manufacturer';
        }

        return $isDoc ? 'technical_doc' : 'other';
    }

    public static function rank(string $type): int
    {
        return self::TYPES[$type][0] ?? 7;
    }

    /**
     * @param  array{published_at?: string|null, modified_at?: string|null, author?: string|null, text_length?: int, injection?: list<string>}  $facts
     * @return array{score: int, indicators: list<string>}
     */
    public static function reliability(string $type, string $url, array $facts, int $outdatedMonths = 24): array
    {
        $score = self::TYPES[$type][1] ?? 40;
        $ind = ['type:'.$type];
        if (! str_starts_with(strtolower($url), 'https://')) {
            $score -= 10;
            $ind[] = 'no_https';
        }
        $date = $facts['modified_at'] ?? $facts['published_at'] ?? null;
        if ($date !== null) {
            $score += 5;
            $ind[] = 'dated';
            if (strtotime($date) < strtotime("-{$outdatedMonths} months")) {
                $score -= 10;
                $ind[] = 'older_than_'.$outdatedMonths.'_months';
            }
        } else {
            $ind[] = 'no_date';
        }
        if (! empty($facts['author'])) {
            $score += 5;
            $ind[] = 'has_author';
        }
        if (($facts['text_length'] ?? 0) < 500) {
            $score -= 15;
            $ind[] = 'thin_content';
        }
        if (! empty($facts['injection'])) {
            $score -= 30;
            $ind[] = 'prompt_injection_suspected';
        }

        return ['score' => max(0, min(100, $score)), 'indicators' => $ind];
    }

    public static function registrableLabel(string $host): string
    {
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n < 2) {
            return $host;
        }
        // example.com.vn / example.co.uk → "example"
        if ($n >= 3 && in_array($parts[$n - 2], ['com', 'co', 'net', 'org', 'gov', 'edu', 'ac'], true) && strlen($parts[$n - 1]) === 2) {
            return $parts[$n - 3];
        }

        return $parts[$n - 2];
    }
}
