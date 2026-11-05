<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Structured outline (sections with evidence references).
 *
 * @property string $id
 */
class ArticleOutline extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_outlines';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'warnings' => 'array',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
