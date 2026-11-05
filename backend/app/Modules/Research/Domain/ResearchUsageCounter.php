<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Monthly usage + in-flight reservations per workspace and metric.
 *
 * @property string $id
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
