<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Normalized incoming bank/provider transaction (platform table).
 *
 * @property string $id
 * @property string $provider
 * @property string $external_id
 * @property string $direction
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $description
 * @property ?string $counterparty
 * @property Carbon $occurred_at
 * @property string $match_status
 * @property ?string $match_reason
 * @property ?string $payment_order_id
 * @property ?string $workspace_id
 * @property ?int $webhook_event_id
 * @property ?string $recorded_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PaymentTransaction extends Model
{
    use HasUlids;

    protected $table = 'payment_transactions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'webhook_event_id' => 'integer',
        ];
    }
}
