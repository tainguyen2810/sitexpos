<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`search_query_page_metrics`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $period_start
 * @property string $period_end
 * @property string $search_type
 * @property string $query_hash
 * @property string $query
 * @property string $page_hash
 * @property string $page_url
 * @property int $clicks
 * @property int $impressions
 * @property float $position
 * @property bool $is_demo
 */
class SearchQueryPageMetric extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'search_query_page_metrics';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position' => 'float',
            'is_demo' => 'boolean',
        ];
    }
}
