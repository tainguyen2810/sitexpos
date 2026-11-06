<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Queries;

use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Contracts\AiSpendReport;

final class AiSpendReportQuery implements AiSpendReport
{
    public function __construct(private readonly AiUsageReport $report, private readonly TenantContext $context) {}

    public function forPeriod(Carbon $from, Carbon $to): array
    {
        $s = $this->report->summary($this->context->workspaceId(), $from, $to);

        return [
            'cost_micro_usd' => (int) ($s['totals']['cost_micro_usd'] ?? 0),
            'requests' => (int) ($s['totals']['requests'] ?? 0),
            'by_task' => array_map(fn (array $r): array => ['task_key' => (string) $r['task_key'], 'cost_micro_usd' => (int) ($r['cost_micro_usd'] ?? 0), 'requests' => (int) ($r['requests'] ?? 0)], $s['by_task']),
        ];
    }
}
