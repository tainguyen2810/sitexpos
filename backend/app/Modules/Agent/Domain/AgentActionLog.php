<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`agent_action_logs`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $site_id
 * @property ?string $agent_run_id
 * @property ?string $agent_task_id
 * @property string $event
 * @property string $level
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property string $actor_type
 * @property ?string $actor_id
 * @property bool $is_demo
 * @property ?Carbon $created_at
 */
class AgentActionLog extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'agent_action_logs';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'is_demo' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
