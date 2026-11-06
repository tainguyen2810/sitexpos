<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A research question the article must answer.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $research_dossier_id
 * @property int $position
 * @property string $question
 * @property ?string $purpose
 * @property string $origin
 * @property string $status
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
