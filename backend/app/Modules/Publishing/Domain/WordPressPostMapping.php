<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`wordpress_post_mappings`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class WordPressPostMapping extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'wordpress_post_mappings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'wp_post_id' => 'integer',
            'last_revision_id' => 'integer',
            'last_published_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
