<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`agent_tasks`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $agent_run_id
 * @property int $sequence
 * @property string $tool
 * @property string $status
 * @property string $decision
 * @property list<string> $decision_reasons
 * @property array<string, mixed> $input
 * @property array<string, mixed> $policy_snapshot
 * @property int $estimated_cost_micro_usd
 * @property int $actual_cost_micro_usd
 * @property ?string $credit_key
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $evidence
 * @property ?string $error_code
 * @property ?string $error_message
 * @property int $attempts
 * @property int $max_attempts
 * @property ?Carbon $next_attempt_at
 * @property ?Carbon $timeout_at
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property string $idempotency_key
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AgentTask extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'agent_tasks';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'decision_reasons' => 'array',
            'input' => 'array',
            'policy_snapshot' => 'array',
            'estimated_cost_micro_usd' => 'integer',
            'actual_cost_micro_usd' => 'integer',
            'output' => 'array',
            'evidence' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'timeout_at' => 'datetime',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
