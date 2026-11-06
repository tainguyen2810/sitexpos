<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`search_performance_daily`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $date
 * @property string $search_type
 * @property string $device
 * @property string $country
 * @property int $clicks
 * @property int $impressions
 * @property float $position
 * @property bool $is_demo
 * @property ?Carbon $synced_at
 */
class SearchPerformanceDaily extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'search_performance_daily';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position' => 'float',
            'is_demo' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
