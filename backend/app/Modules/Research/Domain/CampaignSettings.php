<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Research scope and automation switches of a campaign.
 *
 * @property string $id
 */
class CampaignSettings extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'campaign_settings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'scope_keywords' => 'array',
            'excluded_topics' => 'array',
            'publish_weekdays' => 'array',
            'use_ai_expansion' => 'boolean',
            'auto_discover' => 'boolean',
            'auto_calendar' => 'boolean',
            'auto_create_content' => 'boolean',
            'auto_publish' => 'boolean',
            'notify_opportunities' => 'boolean',
            'ideas_per_run' => 'integer',
            'max_site_pages' => 'integer',
            'max_competitor_pages' => 'integer',
            'posts_per_week' => 'integer',
            'max_ai_cost_per_run_micro_usd' => 'integer',
            'notify_min_score' => 'integer',
        ];
    }

    public static function defaultsFor(SeoCampaign $campaign): self
    {
        $s = new self;
        $s->forceFill([
            'campaign_id' => $campaign->id,
            'ideas_per_run' => 20, 'scope_keywords' => [], 'excluded_topics' => [],
            'max_site_pages' => 30, 'max_competitor_pages' => 15, 'use_ai_expansion' => true,
            'auto_discover' => false, 'auto_calendar' => false, 'auto_create_content' => false, 'auto_publish' => false,
            'posts_per_week' => 3, 'publish_weekdays' => [1, 3, 5], 'max_ai_cost_per_run_micro_usd' => 500000,
            'notify_opportunities' => true, 'notify_min_score' => 70,
        ]);

        return $s;
    }
}
