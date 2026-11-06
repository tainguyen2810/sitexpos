<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Contracts;

use Carbon\CarbonInterface;

/**
 * Read-only view of the SEO Agent's activity for reports and the Copilot
 * (current tenant scope only).
 */
interface AgentActivity
{
    /**
     * @return array{runs: int, tasks_succeeded: int, tasks_failed: int, tasks_awaiting_approval: int, by_tool: array<string, int>}
     */
    public function summary(?string $siteId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Tasks waiting for a human decision.
     *
     * @return list<array{id: string, site_id: string, tool: string, created_at: ?string}>
     */
    public function pendingApprovals(?string $siteId): array;
}
