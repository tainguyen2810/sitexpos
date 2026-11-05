<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Monthly usage + in-flight reservations per workspace and metric.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $period
 * @property string $metric
 * @property int $used
 * @property int $reserved
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ResearchUsageCounter extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_usage_counters';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'reserved' => 'integer',
        ];
    }
}
