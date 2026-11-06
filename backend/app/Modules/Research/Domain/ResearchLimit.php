<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Platform limits: scope_key 'global' (defaults) or 'ws:<id>' (override).
 * Interim entitlement until plans/billing exist.
 *
 * @property string $id
 * @property string $scope_key
 * @property ?string $workspace_id
 * @property int $max_campaigns
 * @property int $max_runs_per_month
 * @property int $max_ideas_per_run
 * @property int $max_competitors_per_campaign
 * @property int $max_calendar_items_per_month
 * @property int $max_concurrent_runs
 * @property int $max_ai_cost_per_run_micro_usd
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ResearchLimit extends Model
{
    use HasUlids;

    protected $table = 'research_limits';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'max_campaigns' => 'integer',
            'max_runs_per_month' => 'integer',
            'max_ideas_per_run' => 'integer',
            'max_competitors_per_campaign' => 'integer',
            'max_calendar_items_per_month' => 'integer',
            'max_concurrent_runs' => 'integer',
            'max_ai_cost_per_run_micro_usd' => 'integer',
        ];
    }
}
