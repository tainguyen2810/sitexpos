<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Counted usage of a resource in a period.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $resource_key
 * @property string $period_start
 * @property int $used
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class UsageMeter extends Model
{
    use BelongsToWorkspace;

    protected $table = 'usage_meters';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'used' => 'integer',
        ];
    }
}
