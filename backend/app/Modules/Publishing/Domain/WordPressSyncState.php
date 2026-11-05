<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`wordpress_sync_states`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class WordPressSyncState extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'wordpress_sync_states';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_revision_id' => 'integer',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
