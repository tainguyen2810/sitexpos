<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`agent_configs`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property bool $enabled
 * @property int $autonomy_level
 * @property bool $emergency_stop
 * @property list<string>|null $allowed_actions
 * @property list<string>|null $forbidden_actions
 * @property int $max_daily_tasks
 * @property int $max_monthly_ai_spend_micro_usd
 * @property int $max_monthly_refreshes
 * @property int $max_monthly_new_articles
 * @property int $publish_window_start
 * @property int $publish_window_end
 * @property string $timezone
 * @property array<string, mixed>|null $approval_requirements
 * @property array<string, mixed>|null $min_evidence
 * @property array<string, mixed>|null $quality_thresholds
 * @property ?string $principal_user_id
 * @property ?string $level3_enabled_by
 * @property ?Carbon $level3_enabled_at
 * @property int $lock_version
 * @property ?string $updated_by
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AgentConfig extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'agent_configs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'autonomy_level' => 'integer',
            'emergency_stop' => 'boolean',
            'allowed_actions' => 'array',
            'forbidden_actions' => 'array',
            'max_daily_tasks' => 'integer',
            'max_monthly_ai_spend_micro_usd' => 'integer',
            'max_monthly_refreshes' => 'integer',
            'max_monthly_new_articles' => 'integer',
            'publish_window_start' => 'integer',
            'publish_window_end' => 'integer',
            'approval_requirements' => 'array',
            'min_evidence' => 'array',
            'quality_thresholds' => 'array',
            'level3_enabled_at' => 'datetime',
            'lock_version' => 'integer',
            'is_demo' => 'boolean',
        ];
    }
}
