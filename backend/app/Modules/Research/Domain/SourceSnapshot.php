<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Structural snapshot of one public page (title, headings, questions, links).
 * Full page text is never stored: no verbatim copy of third-party content.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $research_source_id
 * @property string $url
 * @property string $url_hash
 * @property ?int $http_status
 * @property ?string $title
 * @property ?string $meta_description
 * @property ?string $language
 * @property array<string, mixed>|null $headings
 * @property array<string, mixed>|null $questions
 * @property array<string, mixed>|null $internal_links
 * @property int $word_count
 * @property ?string $content_hash
 * @property Carbon $fetched_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SourceSnapshot extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'source_snapshots';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'questions' => 'array',
            'internal_links' => 'array',
            'word_count' => 'integer',
            'http_status' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
