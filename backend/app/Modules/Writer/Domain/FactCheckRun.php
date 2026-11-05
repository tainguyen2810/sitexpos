<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One fact-check pass over an article version.
 *
 * @property string $id
 */
class FactCheckRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'fact_check_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'consistency_issues' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'round' => 'integer',
            'claims_total' => 'integer',
        ];
    }
}
