<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Section-by-section writer state (checkpoint per section).
 *
 * @property string $id
 */
class ArticleSection extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_sections';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'notes' => 'array',
            'position' => 'integer',
            'level' => 'integer',
            'word_count' => 'integer',
            'cost_micro_usd' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
