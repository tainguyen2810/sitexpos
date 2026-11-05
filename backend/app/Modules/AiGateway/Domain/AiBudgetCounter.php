<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Running total for one budget scope + period. Updated only inside a
 * transaction holding a row lock (SELECT ... FOR UPDATE).
 *
 * @property int $id
 * @property string $scope_key
 * @property string $period
 * @property string $period_start
 * @property int $reserved_micro_usd
 * @property int $spent_micro_usd
 * @property Carbon|null $alerted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiBudgetCounter extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'reserved_micro_usd' => 'integer',
            'spent_micro_usd' => 'integer',
            'alerted_at' => 'datetime',
        ];
    }
}
