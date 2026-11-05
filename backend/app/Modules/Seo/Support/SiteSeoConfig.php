<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Support;

use SiteXPost\Modules\Seo\Domain\SchemaConfig;

/**
 * Per-site SEO/schema policy with safe defaults: no schema injection, no
 * cross-domain canonical, no noindex unless the owner enabled it.
 */
final class SiteSeoConfig
{
    /**
     * @return array{injection_enabled: bool, enabled_types: list<string>, organization_name: string|null, logo_url: string|null,
     *     breadcrumbs_supported: bool, allow_canonical_override: bool, allow_robots_override: bool, updated_at: string|null}
     */
    public static function for(string $siteId): array
    {
        $c = SchemaConfig::query()->where('site_id', $siteId)->first();

        return [
            'injection_enabled' => (bool) ($c?->injection_enabled ?? false),
            'enabled_types' => array_values((array) ($c?->enabled_types ?? ['BlogPosting', 'FAQPage', 'HowTo'])),
            'organization_name' => $c?->organization_name,
            'logo_url' => $c?->logo_url,
            'breadcrumbs_supported' => (bool) ($c?->breadcrumbs_supported ?? false),
            'allow_canonical_override' => (bool) ($c?->allow_canonical_override ?? false),
            'allow_robots_override' => (bool) ($c?->allow_robots_override ?? false),
            'updated_at' => $c?->updated_at?->toIso8601String(),
        ];
    }
}
