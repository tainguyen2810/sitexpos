<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Contracts;

use SiteXPost\Modules\Research\Crawler\FetchException;
use SiteXPost\Modules\Research\Crawler\FetchResult;

/**
 * SSRF-safe, robots-aware fetcher for public pages (used by the Phase 5
 * evidence retriever). Implemented by Crawler\PublicFetcher.
 */
interface PublicWebFetcher
{
    /**
     * @throws FetchException
     */
    public function get(string $url, string $source = 'website_crawl', bool $checkRobots = true): FetchResult;
}
