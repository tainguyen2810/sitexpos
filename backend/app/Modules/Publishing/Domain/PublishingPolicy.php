<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_policies`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $default_mode
 * @property bool $auto_publish_enabled
 * @property ?string $auto_publish_enabled_by
 * @property ?Carbon $auto_publish_enabled_at
 * @property bool $require_seo_pass
 * @property bool $require_media_ready
 * @property bool $allow_update_published
 * @property int $max_posts_per_day
 * @property int $max_posts_per_campaign_per_day
 * @property int $min_minutes_between_posts
 * @property int $window_start_hour
 * @property int $window_end_hour
 * @property array<string, mixed>|null $window_days
 * @property int $max_schedule_days_ahead
 * @property int $max_attempts
 * @property int $missed_grace_minutes
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
