<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One fact-check pass over an article version.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $article_version_id
 * @property ?string $workflow_run_id
 * @property int $round
 * @property string $status
 * @property int $claims_total
 * @property array<string, mixed>|null $counts
 * @property list<array<string, mixed>>|null $consistency_issues
 * @property ?string $model
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
