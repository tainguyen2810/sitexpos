<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateGlobalSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'user_agent' => ['sometimes', 'string', 'min:8', 'max:190', 'regex:/^[\x20-\x7E]+$/'],
            'crawl_delay_ms' => ['sometimes', 'integer', 'min:250', 'max:30000'],
            'max_page_bytes' => ['sometimes', 'integer', 'min:100000', 'max:10000000'],
            'max_sitemap_urls' => ['sometimes', 'integer', 'min:10', 'max:5000'],
            'site_crawl_enabled' => ['sometimes', 'boolean'],
            'competitor_crawl_enabled' => ['sometimes', 'boolean'],
            'snapshot_retention_days' => ['sometimes', 'integer', 'min:7', 'max:730'],
            'profile_refresh_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }
}
