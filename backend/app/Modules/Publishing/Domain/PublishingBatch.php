<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_batches`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class PublishingBatch extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_batches';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'interval_minutes' => 'integer',
            'total' => 'integer',
        ];
    }
}
