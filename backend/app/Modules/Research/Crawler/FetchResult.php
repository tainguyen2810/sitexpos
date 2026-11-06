<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Crawler;

final readonly class FetchResult
{
    public function __construct(
        public string $url,
        public int $status,
        public string $contentType,
        public string $body,
    ) {}
}
