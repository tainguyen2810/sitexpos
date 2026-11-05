<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Immutable article version (content snapshot).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property int $number
 * @property string $source
 * @property string $title
 * @property string $content_html
 * @property string $content_hash
 * @property list<array{key: string, heading: string, hash: string}>|null $section_hashes
 * @property int $word_count
 * @property ?string $parent_version_id
 * @property ?string $change_summary
 * @property ?string $model
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
