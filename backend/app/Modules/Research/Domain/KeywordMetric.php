<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Measured keyword data from one verified source for one period.
 *
 * @property string $id
 */
class KeywordMetric extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'keyword_metrics';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'search_volume' => 'integer',
            'difficulty' => 'integer',
            'cpc_micro_usd' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
            'trend' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'collected_at' => 'datetime',
        ];
    }
}
