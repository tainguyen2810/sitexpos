<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Credit wallet. balance_mc / held_mc are projections of the ledger.
 *
 * @property string $id
 * @property string $workspace_id
 * @property int $balance_mc
 * @property int $held_mc
 * @property int $overdraft_limit_mc
 * @property ?int $low_balance_threshold_mc
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CreditWallet extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'credit_wallets';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'balance_mc' => 'integer',
            'held_mc' => 'integer',
            'overdraft_limit_mc' => 'integer',
            'low_balance_threshold_mc' => 'integer',
        ];
    }
}
