<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Automatic fix or user-requested AI rewrite (with before/after diff).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $kind
 * @property string $action
 * @property int $round
 * @property string $status
 * @property ?string $section_key
 * @property ?string $from_version_id
 * @property ?string $to_version_id
 * @property list<string>|null $claim_ids
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $diff
 * @property ?string $proposed_html
 * @property ?string $model
 * @property ?string $error_code
 * @property ?string $workflow_run_id
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ArticleRevision extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_revisions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'claim_ids' => 'array',
            'request' => 'array',
            'diff' => 'array',
            'round' => 'integer',
        ];
    }
}
