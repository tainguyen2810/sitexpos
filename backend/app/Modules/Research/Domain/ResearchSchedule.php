<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Automatic research cadence of a campaign.
 *
 * @property string $id
 */
class ResearchSchedule extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_schedules';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'hour_utc' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function computeNextRun(?Carbon $from = null): Carbon
    {
        $from = ($from ?? now())->copy()->utc();
        $hour = max(0, min(23, $this->hour_utc));
        $next = $from->copy()->setTime($hour, 0);

        if ($this->frequency === 'daily') {
            return $next->lte($from) ? $next->addDay() : $next;
        }
        if ($this->frequency === 'monthly') {
            $day = max(1, min(28, $this->day_of_month ?? 1));
            $next = $from->copy()->setDay($day)->setTime($hour, 0);

            return $next->lte($from) ? $next->addMonthNoOverflow()->setDay($day) : $next;
        }
        $dow = max(0, min(6, $this->day_of_week ?? 1));
        $next = $from->copy()->setTime($hour, 0);
        while ($next->dayOfWeek !== $dow || $next->lte($from)) {
            $next->addDay()->setTime($hour, 0);
        }

        return $next;
    }
}
