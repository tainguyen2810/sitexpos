<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Refund of a paid order (staff only).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $payment_order_id
 * @property int $amount_minor
 * @property string $currency
 * @property int $credits_reversed_mc
 * @property string $status
 * @property string $reason
 * @property ?string $external_ref
 * @property ?string $failure_reason
 * @property string $requested_by
 * @property ?string $processed_by
 * @property ?Carbon $processed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Refund extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'refunds';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'credits_reversed_mc' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
