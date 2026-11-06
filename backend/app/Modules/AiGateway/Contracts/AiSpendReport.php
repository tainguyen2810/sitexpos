<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Contracts;

use Illuminate\Support\Carbon;

/**
 * Phase 8 — AI spend of the CURRENT workspace (TenantContext) for SEO
 * reports and the Copilot. Read-only, real numbers from ai_requests.
 */
interface AiSpendReport
{
    /**
     * @return array{cost_micro_usd: int, requests: int, by_task: list<array{task_key: string, cost_micro_usd: int, requests: int}>}
     */
    public function forPeriod(Carbon $from, Carbon $to): array;
}
