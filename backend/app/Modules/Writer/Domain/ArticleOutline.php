<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Structured outline (sections with evidence references).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $content_brief_id
 * @property int $version
 * @property string $status
 * @property list<array<string, mixed>>|null $sections
 * @property list<string>|null $warnings
 * @property ?string $generated_by_model
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
