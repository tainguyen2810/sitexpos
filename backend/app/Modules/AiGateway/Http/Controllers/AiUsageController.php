<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\AiGateway\Domain\AiBudget;
use SiteXPost\Modules\AiGateway\Gateway\BudgetManager;
use SiteXPost\Modules\AiGateway\Queries\AiUsageReport;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * AI usage of the current workspace: tokens, images, searches, cost, budget.
 */
final class AiUsageController
{
    public function __invoke(Request $request, Workspace $workspace, AiUsageReport $report, BudgetManager $budgets): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $days = max(1, min(90, $request->integer('days', 30)));
        $to = now()->addMinute();
        $from = now()->subDays($days - 1)->startOfDay();

        $data = $report->summary($workspace->id, $from, $to);
        $data['budgets'] = AiBudget::query()->where('scope_key', AiBudget::scopeKeyFor($workspace->id))->where('is_enabled', true)->get()
            ->map(fn (AiBudget $b): array => [
                'period' => $b->period,
                'limit_micro_usd' => $b->limit_micro_usd,
                'alert_threshold_pct' => $b->alert_threshold_pct,
                'action_on_exceed' => $b->action_on_exceed,
                ...$budgets->status($b),
            ])->all();

        return new JsonResponse(['data' => $data]);
    }
}
