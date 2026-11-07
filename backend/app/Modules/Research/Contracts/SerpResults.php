<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Contracts;

/**
 * Real Google organic results for one keyword (used by the Writer as source
 * CANDIDATES only — every result is still fetched, filtered, scored and
 * verified verbatim). Implemented by Sources\SerpLookup over DataForSEO;
 * Google's own Custom Search JSON API is closed to new customers.
 */
interface SerpResults
{
    /** A provider is configured and enabled by the Super Admin. */
    public function available(): bool;

    /**
     * @return array{results: list<array{rank: int, url: string, title: string, domain: string}>, error: string|null}
     */
    public function top(string $keyword, string $country, string $language, int $limit = 10): array;
}
