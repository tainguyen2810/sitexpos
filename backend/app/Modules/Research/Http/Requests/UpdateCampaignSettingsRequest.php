<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateCampaignSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'ideas_per_run' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'scope_keywords' => ['sometimes', 'array', 'max:50'],
            'scope_keywords.*' => ['string', 'max:100'],
            'excluded_topics' => ['sometimes', 'array', 'max:50'],
            'excluded_topics.*' => ['string', 'max:100'],
            'max_site_pages' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_competitor_pages' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'use_ai_expansion' => ['sometimes', 'boolean'],
            'auto_discover' => ['sometimes', 'boolean'],
            'auto_calendar' => ['sometimes', 'boolean'],
            // Phase 5 switches: stored only; Phase 4 never creates or publishes content.
            'auto_create_content' => ['sometimes', 'boolean'],
            'auto_publish' => ['sometimes', 'boolean'],
            'posts_per_week' => ['sometimes', 'integer', 'min:1', 'max:21'],
            'publish_weekdays' => ['sometimes', 'array', 'min:1', 'max:7'],
            'publish_weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'max_ai_cost_per_run_micro_usd' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'notify_opportunities' => ['sometimes', 'boolean'],
            'notify_min_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
