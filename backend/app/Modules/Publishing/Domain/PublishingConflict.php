<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_conflicts`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class PublishingConflict extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_conflicts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'wp_state' => 'array',
            'expected_state' => 'array',
            'resolved_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
