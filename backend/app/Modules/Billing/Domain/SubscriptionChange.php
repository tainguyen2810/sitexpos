<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Upgrade / downgrade / interval change (pending payment, scheduled or applied).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $subscription_id
 * @property string $kind
 * @property string $from_version_id
 * @property string $to_version_id
 * @property string $to_interval
 * @property string $status
 * @property ?Carbon $effective_at
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed>|null $quote
 * @property ?string $payment_order_id
 * @property ?string $requested_by
 * @property ?Carbon $applied_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SubscriptionChange extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'subscription_changes';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'amount_minor' => 'integer',
            'quote' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
