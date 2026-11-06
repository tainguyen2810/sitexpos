<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`content_performance_snapshots`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $page_hash
 * @property string $page_url
 * @property ?string $article_id
 * @property string $period_end
 * @property int $window_days
 * @property int $clicks
 * @property int $impressions
 * @property ?float $position
 * @property int $prev_clicks
 * @property int $prev_impressions
 * @property ?float $prev_position
 * @property ?float $baseline_clicks
 * @property ?int $yoy_clicks
 * @property int $days_with_data
 * @property string $classification
 * @property list<string>|null $labels
 * @property list<string>|null $reasons
 * @property ?Carbon $published_at
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ContentPerformanceSnapshot extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_performance_snapshots';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'window_days' => 'integer',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position' => 'float',
            'prev_clicks' => 'integer',
            'prev_impressions' => 'integer',
            'prev_position' => 'float',
            'baseline_clicks' => 'float',
            'yoy_clicks' => 'integer',
            'days_with_data' => 'integer',
            'labels' => 'array',
            'reasons' => 'array',
            'published_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
