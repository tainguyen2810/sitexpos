<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Work that failed permanently and needs a human: a workflow run that ran
 * out of attempts (or hit a non-retryable error), or any queued job that
 * landed in failed_jobs. Platform-level (Super Admin) — no tenant scope;
 * customers see their failed runs through workflow_runs.
 *
 * @property string $id
 * @property string|null $workspace_id
 * @property string $source
 * @property string|null $workflow_run_id
 * @property string|null $job_class
 * @property string|null $queue
 * @property string|null $failed_job_uuid
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $attempts
 * @property string $status
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DeadLetter extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'resolved_at' => 'datetime'];
    }
}
