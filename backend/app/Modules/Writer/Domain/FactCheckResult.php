<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Verdict for one claim (model, rule or human reviewer).
 *
 * @property string $id
 */
class FactCheckResult extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'fact_check_results';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'supporting' => 'array',
            'contradicting' => 'array',
            'rule_notes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
