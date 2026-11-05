<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A page / category / question found on a competitor (structure only).
 *
 * @property string $id
 */
class CompetitorFinding extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'competitor_findings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
