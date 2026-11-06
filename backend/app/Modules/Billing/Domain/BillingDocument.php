<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Internal billing document (receipt, order confirmation, refund record) — not a VAT e-invoice.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $type
 * @property string $number
 * @property string $source_key
 * @property ?string $payment_order_id
 * @property ?string $refund_id
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed> $snapshot
 * @property Carbon $issued_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BillingDocument extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'billing_documents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'snapshot' => 'array',
            'issued_at' => 'datetime',
        ];
    }
}
