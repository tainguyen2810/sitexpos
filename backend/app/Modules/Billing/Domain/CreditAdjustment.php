<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Staff credit adjustment (reason mandatory, audited).
 *
 * @property string $id
 * @property string $workspace_id
 * @property int $amount_mc
 * @property string $kind
 * @property string $reason
 * @property ?Carbon $expires_at
 * @property ?int $ledger_entry_id
 * @property string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CreditAdjustment extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'credit_adjustments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_mc' => 'integer',
            'expires_at' => 'datetime',
            'ledger_entry_id' => 'integer',
        ];
    }
}
