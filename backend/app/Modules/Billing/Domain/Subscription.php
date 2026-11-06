<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Workspace subscription (one live subscription per workspace).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $plan_id
 * @property string $plan_version_id
 * @property string $status
 * @property string $interval
 * @property int $price_minor
 * @property string $currency
 * @property Carbon $started_at
 * @property Carbon $current_period_start
 * @property ?Carbon $current_period_end
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $grace_ends_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $ended_at
 * @property ?string $status_before_hold
 * @property bool $renewal_reminders
 * @property ?string $live_key
 * @property int $lock_version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Subscription extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'subscriptions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'renewal_reminders' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
