<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Payment order. Only a verified transaction or a staff review can mark it PAID.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $purpose
 * @property ?string $plan_version_id
 * @property ?string $interval
 * @property ?string $credit_package_id
 * @property ?string $subscription_id
 * @property ?string $subscription_change_id
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 * @property string $provider
 * @property string $reference
 * @property string $status
 * @property int $paid_amount_minor
 * @property int $refunded_amount_minor
 * @property int $fee_minor
 * @property ?string $idempotency_key
 * @property array<string, mixed>|null $snapshot
 * @property ?string $created_by
 * @property Carbon $expires_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $fulfilled_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PaymentOrder extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'payment_orders';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'snapshot' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }
}
