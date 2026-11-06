<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PolicyRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'default_mode' => ['sometimes', 'in:manual,wp_draft'],
            'auto_publish_enabled' => ['sometimes', 'boolean'],
            'confirm_auto_publish' => ['sometimes', 'boolean'],
            'require_seo_pass' => ['sometimes', 'boolean'],
            'require_media_ready' => ['sometimes', 'boolean'],
            'allow_update_published' => ['sometimes', 'boolean'],
            'max_posts_per_day' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_posts_per_campaign_per_day' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'min_minutes_between_posts' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'window_start_hour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'window_end_hour' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'window_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'window_days.*' => ['integer', 'min:1', 'max:7'],
            'max_schedule_days_ahead' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'missed_grace_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ];
    }
}
