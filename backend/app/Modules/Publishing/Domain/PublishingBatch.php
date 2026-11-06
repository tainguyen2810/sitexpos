<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_batches`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $mode
 * @property string $status
 * @property ?Carbon $start_at
 * @property ?int $interval_minutes
 * @property int $total
 * @property ?string $idempotency_key
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
