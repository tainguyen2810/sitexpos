<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Publishing\Domain\PublishingPolicy;
use SiteXPost\Modules\Publishing\Support\PublishingLimits;
use SiteXPost\Modules\Publishing\Support\SitePolicy;

/**
 * Website Publishing Settings. Turning auto-publish on is an explicit,
 * audited decision (`confirm_auto_publish`), allowed only when the
 * platform permits it; the safe default stays manual.
 */
final class UpdatePolicy
{
    public const FIELDS = ['default_mode', 'auto_publish_enabled', 'require_seo_pass', 'require_media_ready', 'allow_update_published', 'max_posts_per_day',
        'max_posts_per_campaign_per_day', 'min_minutes_between_posts', 'window_start_hour', 'window_end_hour', 'window_days', 'max_schedule_days_ahead',
        'max_attempts', 'missed_grace_minutes'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(string $siteId, string $workspaceId, array $data, string $userId): array
    {
        $row = PublishingPolicy::query()->where('site_id', $siteId)->first() ?? (new PublishingPolicy)->forceFill(['site_id' => $siteId]);
        $before = SitePolicy::for($siteId);
        $fields = array_intersect_key($data, array_flip(self::FIELDS));
        if (($fields['auto_publish_enabled'] ?? false) === true && ! $before['auto_publish_enabled']) {
            if (($data['confirm_auto_publish'] ?? false) !== true) {
                throw new ApiException(422, 'auto_publish_confirmation_required', __('publishing.codes.auto_publish_confirmation_required'));
            }
            if (! PublishingLimits::for($workspaceId)['auto_publish_allowed']) {
                throw new ApiException(422, 'auto_publish_not_allowed', __('publishing.codes.auto_publish_not_allowed'));
            }
            $fields['auto_publish_enabled_by'] = $userId;
            $fields['auto_publish_enabled_at'] = now();
        }
        $start = (int) ($fields['window_start_hour'] ?? $before['window_start_hour']);
        $end = (int) ($fields['window_end_hour'] ?? $before['window_end_hour']);
        if ($end <= $start) {
            throw new ApiException(422, 'invalid_window', __('publishing.codes.invalid_window'));
        }
        $row->forceFill([...$fields, 'updated_by' => $userId])->save();
        $after = SitePolicy::for($siteId);
        $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v && $k !== 'updated_at', ARRAY_FILTER_USE_BOTH));
        Audit::record('publishing.policy_updated', $row, ['changed' => $changed, 'auto_publish_enabled' => $after['auto_publish_enabled']]);

        return $after;
    }
}
