<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pre-call hold on budget, keyed by a unique idempotency key so that a
 * retried job can never reserve or charge the same call twice.
 *
 * @property string $id
 * @property string $idempotency_key
 * @property string|null $workspace_id
 * @property string $status
 * @property int $amount_micro_usd
 * @property int|null $actual_micro_usd
 * @property list<int> $counter_ids
 * @property Carbon $expires_at
 * @property Carbon|null $settled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiBudgetReservation extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_micro_usd' => 'integer',
            'actual_micro_usd' => 'integer',
            'counter_ids' => 'array',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
