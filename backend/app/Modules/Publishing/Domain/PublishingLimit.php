<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Platform limits set by Super Admin: scope_key 'global' (defaults) or
 * 'ws:<id>' (override). Interim entitlement until plans/billing exist.
 *
 * @property string $id
 * @property string $scope_key
 * @property string $id
 * @property string $scope_key
 * @property ?int $max_publishes_per_day
 * @property ?int $max_bulk_items
 * @property ?int $max_schedule_days_ahead
 * @property bool $auto_publish_allowed
 * @property bool $publishing_enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PublishingLimit extends Model
{
    use HasUlids;

    protected $table = 'publishing_limits';

    protected $guarded = ['*'];
}
