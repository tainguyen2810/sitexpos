<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Domain;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;

/**
 * Durable workflow run. Status changes ONLY through WorkflowStateMachine
 * (compare-and-set on status + version), never by direct assignment.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $type
 * @property string $queue
 * @property WorkflowStatus $status
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $current_step
 * @property int $attempts
 * @property int $max_attempts
 * @property int $cycle
 * @property int $version
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $dispatched_at
 * @property string|null $locked_by
 * @property Carbon|null $locked_until
 * @property Carbon|null $cancel_requested_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $job_run_id
 * @property string|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, WorkflowStep> $steps
 * @property-read Collection<int, WorkflowEvent> $events
 */
class WorkflowRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'cycle' => 1, 'version' => 0, 'max_attempts' => 3];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'input' => 'array',
            'output' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'cycle' => 'integer',
            'version' => 'integer',
            'next_attempt_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'locked_until' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<WorkflowEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class)->orderBy('id');
    }
}
