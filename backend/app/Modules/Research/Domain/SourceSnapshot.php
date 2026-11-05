<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Structural snapshot of one public page (title, headings, questions, links).
 * Full page text is never stored: no verbatim copy of third-party content.
 *
 * @property string $id
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
