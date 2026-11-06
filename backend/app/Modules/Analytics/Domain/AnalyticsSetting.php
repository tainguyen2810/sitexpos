<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`analytics_settings`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property array<string, mixed>|null $thresholds
 * @property array<string, mixed>|null $health_weights
 * @property int $backfill_days
 * @property int $raw_retention_days
 * @property int $lock_version
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AnalyticsSetting extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'analytics_settings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'thresholds' => 'array',
            'health_weights' => 'array',
            'backfill_days' => 'integer',
            'raw_retention_days' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
