<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_policies`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class PublishingPolicy extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_policies';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'auto_publish_enabled' => 'boolean',
            'auto_publish_enabled_at' => 'datetime',
            'require_seo_pass' => 'boolean',
            'require_media_ready' => 'boolean',
            'allow_update_published' => 'boolean',
            'max_posts_per_day' => 'integer',
            'max_posts_per_campaign_per_day' => 'integer',
            'min_minutes_between_posts' => 'integer',
            'window_start_hour' => 'integer',
            'window_end_hour' => 'integer',
            'window_days' => 'array',
            'max_schedule_days_ahead' => 'integer',
            'max_attempts' => 'integer',
            'missed_grace_minutes' => 'integer',
        ];
    }
}
