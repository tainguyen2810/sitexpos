<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Spending cap for the whole platform (scope=global) or one workspace.
 * Managed by Super Admin only, so it is a platform model (no tenant scope).
 *
 * @property string $id
 * @property string $scope
 * @property string|null $workspace_id
 * @property string $scope_key
 * @property string $period
 * @property int $limit_micro_usd
 * @property int $alert_threshold_pct
 * @property string $action_on_exceed
 * @property bool $is_enabled
 * @property string|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiBudget extends Model
{
    use HasUlids;

    public const PERIODS = ['day', 'month'];

    public const ACTIONS = ['alert', 'pause_batch', 'pause_all'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'limit_micro_usd' => 'integer',
            'alert_threshold_pct' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public static function scopeKeyFor(?string $workspaceId): string
    {
        return $workspaceId === null ? 'global' : 'ws:'.$workspaceId;
    }

    public static function periodStart(string $period, ?Carbon $at = null): string
    {
        $at = ($at ?? now())->copy()->utc();

        return $period === 'day' ? $at->toDateString() : $at->startOfMonth()->toDateString();
    }
}
