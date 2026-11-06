<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Section-by-section writer state (checkpoint per section).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $outline_id
 * @property string $section_key
 * @property int $position
 * @property string $heading
 * @property int $level
 * @property string $status
 * @property ?string $content_html
 * @property int $word_count
 * @property list<array<string, mixed>>|null $citations
 * @property list<array<string, mixed>>|null $notes
 * @property ?string $model
 * @property int $cost_micro_usd
 * @property int $attempts
 * @property ?string $error_code
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
