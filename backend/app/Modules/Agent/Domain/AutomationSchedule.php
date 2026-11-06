<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`automation_schedules`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $kind
 * @property string $frequency
 * @property ?int $day_of_week
 * @property ?int $day_of_month
 * @property string $local_time
 * @property string $timezone
 * @property string $status
 * @property ?Carbon $next_run_at
 * @property ?Carbon $last_run_at
 * @property ?string $last_run_id
 * @property ?string $last_status
 * @property int $consecutive_failures
 * @property bool $notify_on_failure
 * @property ?string $created_by
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AutomationSchedule extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'automation_schedules';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'notify_on_failure' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }
}
