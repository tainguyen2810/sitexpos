<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Support;

use SiteXPost\Modules\Publishing\Domain\PublishingPolicy;

/**
 * Website publishing policy with the safe defaults (manual approval, no
 * auto-publish, SEO pass and media ready required).
 */
final class SitePolicy
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $siteId): array
    {
        $p = PublishingPolicy::query()->where('site_id', $siteId)->first();

        return [
            'site_id' => $siteId,
            'default_mode' => $p->default_mode ?? 'manual',
            'auto_publish_enabled' => (bool) ($p->auto_publish_enabled ?? false),
            'auto_publish_enabled_by' => $p?->auto_publish_enabled_by,
            'auto_publish_enabled_at' => $p?->auto_publish_enabled_at?->toIso8601ZuluString(),
            'require_seo_pass' => (bool) ($p->require_seo_pass ?? true),
            'require_media_ready' => (bool) ($p->require_media_ready ?? true),
            'allow_update_published' => (bool) ($p->allow_update_published ?? true),
            'max_posts_per_day' => (int) ($p->max_posts_per_day ?? 5),
            'max_posts_per_campaign_per_day' => (int) ($p->max_posts_per_campaign_per_day ?? 3),
            'min_minutes_between_posts' => (int) ($p->min_minutes_between_posts ?? 60),
            'window_start_hour' => (int) ($p->window_start_hour ?? 7),
            'window_end_hour' => (int) ($p->window_end_hour ?? 22),
            'window_days' => $p?->window_days !== null ? array_values(array_map('intval', (array) $p->window_days)) : null,
            'max_schedule_days_ahead' => (int) ($p->max_schedule_days_ahead ?? 90),
            'max_attempts' => (int) ($p->max_attempts ?? 5),
            'missed_grace_minutes' => (int) ($p->missed_grace_minutes ?? 180),
            'updated_at' => $p?->updated_at?->toIso8601ZuluString(),
        ];
    }
}
