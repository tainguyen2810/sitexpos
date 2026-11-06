<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Credit top-up package sold for money.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $credits_mc
 * @property int $bonus_credits_mc
 * @property int $price_minor
 * @property string $currency
 * @property ?int $validity_days
 * @property string $status
 * @property bool $is_demo
 * @property int $display_order
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CreditPackage extends Model
{
    use HasUlids;

    protected $table = 'credit_packages';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'credits_mc' => 'integer',
            'bonus_credits_mc' => 'integer',
            'price_minor' => 'integer',
            'validity_days' => 'integer',
            'is_demo' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
