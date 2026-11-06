<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`experiment_observations`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $experiment_id
 * @property string $date
 * @property string $phase
 * @property int $clicks
 * @property int $impressions
 * @property ?float $position
 * @property bool $is_demo
 */
class ExperimentObservation extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'experiment_observations';

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
