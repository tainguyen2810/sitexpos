<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;

/**
 * One usage limit of a plan version (-1 = unlimited).
 *
 * @property int $id
 * @property string $plan_version_id
 * @property string $resource_key
 * @property int $limit_value
 * @property string $period
 */
class PlanEntitlement extends Model
{
    protected $table = 'plan_entitlements';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
        ];
    }
}
