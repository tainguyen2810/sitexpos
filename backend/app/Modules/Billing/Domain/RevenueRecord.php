<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Cash collected + recognition window (accounting).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $kind
 * @property string $source_key
 * @property ?string $payment_order_id
 * @property ?string $refund_id
 * @property ?string $plan_id
 * @property int $amount_minor
 * @property int $discount_minor
 * @property string $currency
 * @property ?Carbon $service_start
 * @property ?Carbon $service_end
 * @property ?string $credit_grant_id
 * @property Carbon $occurred_at
 * @property ?Carbon $created_at
 */
class RevenueRecord extends Model
{
    use BelongsToWorkspace;

    protected $table = 'revenue_records';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'discount_minor' => 'integer',
            'service_start' => 'datetime',
            'service_end' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }
}
