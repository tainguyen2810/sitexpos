<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Credit bucket (included, purchased, promotional, ...) consumed earliest-expiry first.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $wallet_id
 * @property string $source
 * @property int $amount_mc
 * @property int $remaining_mc
 * @property ?Carbon $expires_at
 * @property string $source_ref
 * @property int $price_minor
 * @property ?string $currency
 * @property ?Carbon $expired_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CreditGrant extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'credit_grants';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_mc' => 'integer',
            'remaining_mc' => 'integer',
            'expires_at' => 'datetime',
            'price_minor' => 'integer',
            'expired_at' => 'datetime',
        ];
    }
}
