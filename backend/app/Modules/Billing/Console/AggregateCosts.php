<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use SiteXPost\Modules\Billing\Accounting\CostAggregator;

final class AggregateCosts extends Command
{
    protected $signature = 'sitex:billing:aggregate-costs {--days=2}';

    protected $description = 'Copy daily AI cost from ai_requests into cost_records';

    public function handle(CostAggregator $aggregator): int
    {
        $days = max(1, min(120, (int) $this->option('days')));
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i)->startOfDay();
            $this->line($day->toDateString().': '.$aggregator->aggregate($day).' rows');
        }

        return self::SUCCESS;
    }
}
