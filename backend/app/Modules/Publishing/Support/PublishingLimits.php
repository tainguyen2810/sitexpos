<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Support;

use SiteXPost\Modules\Publishing\Domain\PublishingLimit;

/**
 * Platform publishing limits: config → global row → workspace row (Super
 * Admin). `publishing_enabled = false` is the global kill switch.
 */
final class PublishingLimits
{
    /**
     * @return array{max_publishes_per_day: int, max_bulk_items: int, max_schedule_days_ahead: int, auto_publish_allowed: bool, publishing_enabled: bool}
     */
    public static function for(string $workspaceId): array
    {
        $out = [
            'max_publishes_per_day' => (int) config('sitex.publishing.max_publishes_per_day', 50),
            'max_bulk_items' => (int) config('sitex.publishing.max_bulk_items', 50),
            'max_schedule_days_ahead' => (int) config('sitex.publishing.max_schedule_days_ahead', 180),
            'auto_publish_allowed' => true,
            'publishing_enabled' => true,
        ];
        foreach (['global', 'ws:'.$workspaceId] as $scope) {
            $row = PublishingLimit::query()->where('scope_key', $scope)->first();
            if ($row === null) {
                continue;
            }
            foreach (['max_publishes_per_day', 'max_bulk_items', 'max_schedule_days_ahead'] as $k) {
                if ($row->getAttribute($k) !== null) {
                    $out[$k] = (int) $row->getAttribute($k);
                }
            }
            $out['auto_publish_allowed'] = $out['auto_publish_allowed'] && (bool) $row->auto_publish_allowed;
            $out['publishing_enabled'] = $out['publishing_enabled'] && (bool) $row->publishing_enabled;
        }

        return $out;
    }
}
