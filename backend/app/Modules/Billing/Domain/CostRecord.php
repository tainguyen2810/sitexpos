<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Daily AI cost snapshot per workspace/category/model.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $day
 * @property string $category
 * @property string $provider
 * @property string $model
 * @property int $requests
 * @property int $cost_micro_usd
 * @property int $uncertain_micro_usd
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CostRecord extends Model
{
    use BelongsToWorkspace;

    protected $table = 'cost_records';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'requests' => 'integer',
            'cost_micro_usd' => 'integer',
            'uncertain_micro_usd' => 'integer',
        ];
    }
}
