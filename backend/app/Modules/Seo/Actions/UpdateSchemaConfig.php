<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Seo\Domain\SchemaConfig;

/**
 * Website SEO settings: schema injection toggle/types and the policy that
 * allows dangerous metadata (cross-domain canonical, noindex). Audited.
 */
final class UpdateSchemaConfig
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(string $siteId, array $data, string $userId): SchemaConfig
    {
        $row = SchemaConfig::query()->where('site_id', $siteId)->first() ?? (new SchemaConfig)->forceFill(['site_id' => $siteId, 'enabled_types' => ['BlogPosting', 'FAQPage', 'HowTo']]);
        $before = $row->exists ? $row->only(['injection_enabled', 'enabled_types', 'allow_canonical_override', 'allow_robots_override']) : [];
        $fields = array_intersect_key($data, array_flip(['injection_enabled', 'enabled_types', 'organization_name', 'logo_url', 'breadcrumbs_supported', 'allow_canonical_override', 'allow_robots_override']));
        if (isset($fields['enabled_types'])) {
            $fields['enabled_types'] = array_values(array_unique((array) $fields['enabled_types']));
        }
        $row->forceFill([...$fields, 'updated_by' => $userId])->save();
        Audit::record('seo.site_config_updated', $row, ['before' => $before, 'after' => $row->only(['injection_enabled', 'enabled_types', 'allow_canonical_override', 'allow_robots_override'])]);

        return $row;
    }
}
