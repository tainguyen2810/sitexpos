<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Measured keyword data from one verified source for one period.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $keyword_id
 * @property string $source
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property ?int $search_volume
 * @property ?int $difficulty
 * @property ?int $cpc_micro_usd
 * @property ?int $impressions
 * @property ?int $clicks
 * @property ?float $ctr
 * @property ?float $position
 * @property array<string, mixed>|null $trend
 * @property Carbon $collected_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
