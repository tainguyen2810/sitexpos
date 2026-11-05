<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Rubric-based quality audit of a version.
 *
 * @property string $id
 */
class QualityReport extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'quality_reports';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'issues' => 'array',
            'duplicates' => 'array',
            'ai_notes' => 'array',
            'score' => 'integer',
        ];
    }
}
