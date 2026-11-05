<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A research question the article must answer.
 *
 * @property string $id
 */
class ResearchQuestion extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_questions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
