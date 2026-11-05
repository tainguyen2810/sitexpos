<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Link between an article and a Phase 3 workflow run.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $workflow_run_id
 * @property string $kind
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ArticleWorkflowRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_workflow_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
        ];
    }
}
