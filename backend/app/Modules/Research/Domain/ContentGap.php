<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A topic the site has not covered (or covers with overlap/cannibalization).
 *
 * @property string $id
 */
class ContentGap extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'content_gaps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'related_content_item_ids' => 'array',
            'score' => 'integer',
        ];
    }
}
