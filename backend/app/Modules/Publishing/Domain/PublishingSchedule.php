<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`publishing_schedules`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $publishing_job_id
 * @property Carbon $scheduled_at
 * @property string $timezone
 * @property string $local_time
 * @property string $status
 * @property ?Carbon $dispatched_at
 * @property ?string $note
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PublishingSchedule extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'publishing_schedules';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }
}
