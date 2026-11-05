<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Rubric-based quality audit of a version.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $article_version_id
 * @property string $rubric_version
 * @property int $score
 * @property array<string, mixed>|null $criteria
 * @property list<array<string, mixed>>|null $issues
 * @property list<array<string, mixed>>|null $duplicates
 * @property array<string, mixed>|null $ai_notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
