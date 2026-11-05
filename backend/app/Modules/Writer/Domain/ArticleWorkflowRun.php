<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Link between an article and a Phase 3 workflow run.
 *
 * @property string $id
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
