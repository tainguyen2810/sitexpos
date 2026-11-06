<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Phase 8 platform row (`agent_policies`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $scope
 * @property bool $kill_switch
 * @property ?string $kill_reason
 * @property int $max_autonomy_level
 * @property list<string>|null $forbidden_tools
 * @property int $max_tasks_per_run
 * @property int $max_concurrent_runs
 * @property int $task_timeout_seconds
 * @property int $run_timeout_minutes
 * @property int $lock_version
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AgentPolicy extends Model
{
    use HasUlids;

    protected $table = 'agent_policies';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kill_switch' => 'boolean',
            'max_autonomy_level' => 'integer',
            'forbidden_tools' => 'array',
            'max_tasks_per_run' => 'integer',
            'max_concurrent_runs' => 'integer',
            'task_timeout_seconds' => 'integer',
            'run_timeout_minutes' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
