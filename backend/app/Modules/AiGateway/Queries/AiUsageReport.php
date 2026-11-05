<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Token / image / search usage and cost aggregates from ai_requests.
 * Workspace reports always pass the workspace id taken from TenantContext;
 * the platform report passes null (all tenants, Super Admin only).
 * Only constant SQL expressions are used in selectRaw (no user input).
 */
final class AiUsageReport
{
    private const SUMS = 'COUNT(*) as requests,'
        .' SUM(CASE WHEN status = \'succeeded\' THEN 1 ELSE 0 END) as succeeded,'
        .' SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed,'
        .' SUM(input_tokens) as input_tokens, SUM(cached_input_tokens) as cached_input_tokens,'
        .' SUM(cache_write_tokens) as cache_write_tokens, SUM(output_tokens) as output_tokens,'
        .' SUM(reasoning_tokens) as reasoning_tokens, SUM(image_count) as images, SUM(search_count) as searches,'
        .' SUM(cost_micro_usd) as cost_micro_usd';

    /**
     * @return array<string, mixed>
     */
    public function summary(?string $workspaceId, Carbon $from, Carbon $to, bool $platform = false): array
    {
        $report = [
            'period' => ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()],
            'totals' => $this->normalise((array) ($this->base($workspaceId, $from, $to)->selectRaw(self::SUMS)->first() ?? [])),
            'by_day' => $this->grouped($workspaceId, $from, $to, 'DATE(created_at)', 'date'),
            'by_task' => $this->grouped($workspaceId, $from, $to, 'task_key', 'task_key'),
            'by_model' => $this->base($workspaceId, $from, $to)
                ->selectRaw('provider, model, '.self::SUMS)->groupBy('provider', 'model')->orderByDesc('cost_micro_usd')->limit(50)->get()
                ->map(fn (object $r): array => ['provider' => $r->provider, 'model' => $r->model, ...$this->normalise((array) $r)])->all(),
            'errors' => $this->base($workspaceId, $from, $to)->where('status', '!=', 'succeeded')
                ->selectRaw('error_code, COUNT(*) as count')->groupBy('error_code')->orderByDesc('count')->limit(20)->get()
                ->map(fn (object $r): array => ['error_code' => $r->error_code ?? 'unknown', 'count' => (int) $r->count])->all(),
        ];

        if ($platform) {
            $report['by_provider'] = $this->grouped(null, $from, $to, 'provider', 'provider');
            $report['by_workspace'] = $this->base(null, $from, $to)
                ->leftJoin('workspaces', 'workspaces.id', '=', 'ai_requests.workspace_id')
                ->selectRaw('ai_requests.workspace_id as workspace_id, workspaces.name as workspace_name, COUNT(*) as requests,'
                    .' SUM(input_tokens + cached_input_tokens + cache_write_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost_micro_usd) as cost_micro_usd')
                ->groupBy('ai_requests.workspace_id', 'workspaces.name')->orderByDesc('cost_micro_usd')->limit(20)->get()
                ->map(fn (object $r): array => [
                    'workspace_id' => $r->workspace_id, 'workspace_name' => $r->workspace_name,
                    'requests' => (int) $r->requests, 'input_tokens' => (int) $r->input_tokens,
                    'output_tokens' => (int) $r->output_tokens, 'cost_micro_usd' => (int) $r->cost_micro_usd,
                ])->all();
        }

        return $report;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grouped(?string $workspaceId, Carbon $from, Carbon $to, string $expression, string $alias): array
    {
        return $this->base($workspaceId, $from, $to)
            ->selectRaw("{$expression} as {$alias}, ".self::SUMS)
            ->groupByRaw($expression)->orderByRaw($expression)->get()
            ->map(fn (object $r): array => [$alias => (string) $r->{$alias}, ...$this->normalise((array) $r)])->values()->all();
    }

    private function base(?string $workspaceId, Carbon $from, Carbon $to): Builder
    {
        $q = DB::table('ai_requests')->where('ai_requests.created_at', '>=', $from)->where('ai_requests.created_at', '<', $to);
        if ($workspaceId !== null) {
            $q->where('ai_requests.workspace_id', $workspaceId);
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int>
     */
    private function normalise(array $row): array
    {
        $out = [];
        foreach (['requests', 'succeeded', 'failed', 'input_tokens', 'cached_input_tokens', 'cache_write_tokens', 'output_tokens', 'reasoning_tokens', 'images', 'searches', 'cost_micro_usd'] as $k) {
            $out[$k] = (int) ($row[$k] ?? 0);
        }

        return $out;
    }
}
