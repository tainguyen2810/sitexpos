<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable once published: prices, trial, credits and entitlements of a plan.
 *
 * @property string $id
 * @property string $plan_id
 * @property int $version
 * @property string $status
 * @property string $currency
 * @property ?int $monthly_price_minor
 * @property ?int $yearly_price_minor
 * @property int $trial_days
 * @property int $trial_credits_mc
 * @property int $included_credits_mc
 * @property ?string $support_level
 * @property array<string, mixed>|null $feature_flags
 * @property ?string $notes
 * @property ?Carbon $published_at
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PlanVersion extends Model
{
    use HasUlids;

    protected $table = 'plan_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'monthly_price_minor' => 'integer',
            'yearly_price_minor' => 'integer',
            'trial_days' => 'integer',
            'trial_credits_mc' => 'integer',
            'included_credits_mc' => 'integer',
            'feature_flags' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
