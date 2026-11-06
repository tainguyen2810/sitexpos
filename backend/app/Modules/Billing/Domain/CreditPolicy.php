<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Versioned AI cost → credit conversion policy (never applied retroactively).
 *
 * @property string $id
 * @property int $version
 * @property int $micro_usd_per_credit
 * @property int $markup_bps
 * @property int $min_charge_mc
 * @property Carbon $effective_from
 * @property ?string $notes
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CreditPolicy extends Model
{
    use HasUlids;

    protected $table = 'credit_policies';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'micro_usd_per_credit' => 'integer',
            'markup_bps' => 'integer',
            'min_charge_mc' => 'integer',
            'effective_from' => 'datetime',
        ];
    }
}
