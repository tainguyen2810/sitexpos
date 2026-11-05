<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Automatic fix or user-requested AI rewrite (with before/after diff).
 *
 * @property string $id
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
