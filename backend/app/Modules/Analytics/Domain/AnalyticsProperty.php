<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`analytics_properties`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $provider
 * @property ?string $property_ref
 * @property string $status
 * @property ?string $first_date
 * @property ?string $last_complete_date
 * @property ?Carbon $last_sync_at
 * @property ?string $last_sync_status
 * @property ?string $last_error_code
 * @property int $consecutive_failures
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AnalyticsProperty extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'analytics_properties';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'is_demo' => 'boolean',
        ];
    }
}
