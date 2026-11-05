<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform limits: scope_key 'global' (defaults) or 'ws:<id>' (override).
 * Interim entitlement until plans/billing exist.
 *
 * @property string $id
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
