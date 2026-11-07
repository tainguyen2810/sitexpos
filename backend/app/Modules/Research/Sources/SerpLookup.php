<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Sources;

use SiteXPost\Modules\Research\Contracts\SerpResults;

/**
 * Google top results via DataForSEO (serp/google/organic/live/regular).
 * Never throws: an unconfigured or failing provider returns no results and
 * an error code, so the caller simply falls back to AI web search.
 */
final class SerpLookup implements SerpResults
{
    public function __construct(private readonly DataForSeoClient $client) {}

    public function available(): bool
    {
        return $this->client->isAvailable();
    }

    public function top(string $keyword, string $country, string $language, int $limit = 10): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['results' => [], 'error' => 'empty_keyword'];
        }
        if (! $this->available()) {
            return ['results' => [], 'error' => 'not_configured'];
        }
        try {
            return ['results' => $this->client->organic($keyword, $country, $language, max(1, min(10, $limit))), 'error' => null];
        } catch (ProviderUnavailable $e) {
            return ['results' => [], 'error' => $e->errorCode];
        }
    }
}
