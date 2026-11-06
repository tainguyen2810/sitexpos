<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Workflows\Enums\StepStatus;

/**
 * Checkpoint of one step: a succeeded step is never executed again, which
 * makes recovery after a restart resume where the run stopped.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $workflow_run_id
 * @property string $key
 * @property int $sequence
 * @property StepStatus $status
 * @property int $attempts
 * @property array<string, mixed>|null $output
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkflowStep extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'output' => 'array',
            'attempts' => 'integer',
            'sequence' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
