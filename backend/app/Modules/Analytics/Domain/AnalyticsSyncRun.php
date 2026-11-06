<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`analytics_sync_runs`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $provider
 * @property string $kind
 * @property string $status
 * @property ?string $range_start
 * @property ?string $range_end
 * @property ?string $checkpoint_date
 * @property array<string, mixed>|null $progress
 * @property int $rows_upserted
 * @property int $api_requests
 * @property int $attempts
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?Carbon $next_retry_at
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?string $job_run_id
 * @property ?string $triggered_by
 * @property ?string $idempotency_key
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AnalyticsSyncRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'analytics_sync_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'progress' => 'array',
            'rows_upserted' => 'integer',
            'api_requests' => 'integer',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
