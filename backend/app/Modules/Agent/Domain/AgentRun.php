<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`agent_runs`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $schedule_id
 * @property string $trigger
 * @property string $kind
 * @property string $status
 * @property string $phase
 * @property ?string $active_key
 * @property int $autonomy_level
 * @property array<string, mixed> $policy_snapshot
 * @property int $max_tasks
 * @property int $estimated_cost_micro_usd
 * @property int $actual_cost_micro_usd
 * @property array<string, mixed>|null $observations
 * @property list<array<string, mixed>>|null $plan
 * @property array<string, mixed>|null $summary
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?Carbon $scheduled_for
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $deadline_at
 * @property ?string $created_by
 * @property ?string $cancelled_by
 * @property ?string $idempotency_key
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AgentRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'agent_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'autonomy_level' => 'integer',
            'policy_snapshot' => 'array',
            'max_tasks' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'actual_cost_micro_usd' => 'integer',
            'observations' => 'array',
            'plan' => 'array',
            'summary' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'deadline_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
