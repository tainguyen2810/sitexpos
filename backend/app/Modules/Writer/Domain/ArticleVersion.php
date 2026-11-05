<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Immutable article version (content snapshot).
 *
 * @property string $id
 */
class ArticleVersion extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'article_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'section_hashes' => 'array',
            'number' => 'integer',
            'word_count' => 'integer',
        ];
    }
}
