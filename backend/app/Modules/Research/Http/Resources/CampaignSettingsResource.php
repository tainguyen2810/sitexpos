<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\CampaignSettings;

/**
 * @mixin CampaignSettings
 */
final class CampaignSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ideas_per_run' => $this->ideas_per_run,
            'scope_keywords' => $this->scope_keywords ?? [],
            'excluded_topics' => $this->excluded_topics ?? [],
            'max_site_pages' => $this->max_site_pages,
            'max_competitor_pages' => $this->max_competitor_pages,
            'use_ai_expansion' => $this->use_ai_expansion,
            'auto_discover' => $this->auto_discover,
            'auto_calendar' => $this->auto_calendar,
            'auto_create_content' => $this->auto_create_content,
            'auto_publish' => $this->auto_publish,
            'posts_per_week' => $this->posts_per_week,
            'publish_weekdays' => $this->publish_weekdays ?? [1, 3, 5],
            'max_ai_cost_per_run_micro_usd' => $this->max_ai_cost_per_run_micro_usd,
            'notify_opportunities' => $this->notify_opportunities,
            'notify_min_score' => $this->notify_min_score,
        ];
    }
}
