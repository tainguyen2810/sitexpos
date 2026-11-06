<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Credit hold for one metered call, keyed by the AI Gateway reservation key.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $wallet_id
 * @property string $idempotency_key
 * @property string $purpose
 * @property ?string $category
 * @property ?string $policy_id
 * @property int $estimate_micro_usd
 * @property int $amount_mc
 * @property string $status
 * @property ?int $actual_micro_usd
 * @property ?int $charged_mc
 * @property int $uncollected_mc
 * @property bool $needs_review
 * @property Carbon $expires_at
 * @property ?Carbon $settled_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CreditReservation extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'credit_reservations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'estimate_micro_usd' => 'integer',
            'amount_mc' => 'integer',
            'actual_micro_usd' => 'integer',
            'charged_mc' => 'integer',
            'uncollected_mc' => 'integer',
            'needs_review' => 'boolean',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
