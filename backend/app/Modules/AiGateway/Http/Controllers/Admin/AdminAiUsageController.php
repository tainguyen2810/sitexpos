<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Modules\AiGateway\Queries\AiUsageReport;

/**
 * Platform-wide AI cost analytics (all tenants, Super Admin / Billing Admin).
 */
final class AdminAiUsageController
{
    public function __invoke(Request $request, AiUsageReport $report): JsonResponse
    {
        $days = max(1, min(366, $request->integer('days', 30)));
        $workspace = $request->query('workspace_id');
        $workspaceId = is_string($workspace) && Str::isUlid($workspace) ? strtolower($workspace) : null;

        return new JsonResponse(['data' => $report->summary($workspaceId, now()->subDays($days - 1)->startOfDay(), now()->addMinute(), platform: $workspaceId === null)]);
    }
}
