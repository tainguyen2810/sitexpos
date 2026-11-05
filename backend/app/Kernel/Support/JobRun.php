<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Progress record for long-running tenant tasks, polled by the UI
 * (`GET /workspaces/{ws}/job-runs/{id}`). Phase 1 only provides the
 * table/endpoint; AI pipelines will write to it from Phase 4.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $type
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $status
 * @property int $progress
 * @property string|null $step
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class JobRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued', 'progress' => 0, 'subject_type' => null, 'subject_id' => null, 'step' => null,
        'error_code' => null, 'error_message' => null, 'created_by' => null, 'started_at' => null, 'finished_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
