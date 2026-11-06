<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Billing\Support\CostCategory;

/**
 * Copies daily AI cost from `ai_requests` (Phase 3 log, pruned after 90
 * days) into `cost_records` so profitability history survives the prune.
 * Re-running a day overwrites that day's rows (idempotent upsert).
 */
final class CostAggregator
{
    public function aggregate(CarbonImmutable $day): int
    {
        $start = $day->startOfDay();
        $end = $start->addDay();
        // Platform-wide aggregate (all tenants) by design: accounting job, read-only on ai_requests.
        $rows = DB::table('ai_requests')->whereNotNull('workspace_id')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('workspace_id, task_key, provider, model, COUNT(*) AS n, SUM(cost_micro_usd) AS cost, SUM(CASE WHEN billing_uncertain = 1 THEN cost_micro_usd ELSE 0 END) AS uncertain')
            ->groupBy('workspace_id', 'task_key', 'provider', 'model')->get();

        $agg = [];
        foreach ($rows as $r) {
            $k = $r->workspace_id.'|'.CostCategory::of((string) $r->task_key).'|'.$r->provider.'|'.$r->model;
            $agg[$k] ??= ['workspace_id' => (string) $r->workspace_id, 'category' => CostCategory::of((string) $r->task_key), 'provider' => mb_substr((string) $r->provider, 0, 40), 'model' => mb_substr((string) $r->model, 0, 120), 'requests' => 0, 'cost' => 0, 'uncertain' => 0];
            $agg[$k]['requests'] += (int) $r->n;
            $agg[$k]['cost'] += (int) $r->cost;
            $agg[$k]['uncertain'] += (int) $r->uncertain;
        }

        DB::transaction(function () use ($agg, $start): void {
            DB::table('cost_records')->where('day', $start->toDateString())->delete();
            foreach (array_chunk(array_values($agg), 200) as $chunk) {
                DB::table('cost_records')->insert(array_map(fn (array $a) => [
                    'workspace_id' => $a['workspace_id'], 'day' => $start->toDateString(), 'category' => $a['category'],
                    'provider' => $a['provider'], 'model' => $a['model'], 'requests' => $a['requests'],
                    'cost_micro_usd' => $a['cost'], 'uncertain_micro_usd' => $a['uncertain'],
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
        });

        return count($agg);
    }
}
