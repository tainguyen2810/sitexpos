<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Http\Requests\Admin\UpdateWriterSettingsRequest;
use SiteXPost\Modules\Writer\Support\SourceFilter;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Super Admin AI Writer console: limits & policies (global and per
 * workspace), model routing overview (edited in the AI Gateway console —
 * no model is hard-coded here), cost analytics, workflow monitor, failed
 * runs, fact-check failures, quality distribution and abuse signals.
 * Cross-tenant reads are reviewed, read-only scope bypasses.
 */
final class AdminWriterController
{
    private const TYPES = ['writer.research', 'writer.outline', 'writer.draft', 'writer.recheck', 'writer.rewrite'];

    public function __construct(private readonly TenantContext $context, private readonly WriterSettings $settings) {}

    public function settings(): JsonResponse
    {
        return new JsonResponse(['data' => ['values' => $this->settings->all(), 'defaults' => WriterSettings::DEFAULTS, 'workspace_keys' => WriterSettings::WORKSPACE_KEYS]]);
    }

    public function updateSettings(UpdateWriterSettingsRequest $request): JsonResponse
    {
        $values = $request->validated();
        if (isset($values['blocked_source_domains'])) {
            $values['blocked_source_domains'] = array_values(array_unique(array_filter(array_map(
                fn ($d) => SourceFilter::normalizeDomain((string) $d), (array) $values['blocked_source_domains']))));
        }
        $this->assertBudgetOrder($values, null);
        $before = $this->settings->all();
        $this->settings->save(null, $values, (string) $request->user()?->getAuthIdentifier());
        Audit::record('admin.writer.settings_updated', null, ['before' => array_intersect_key($before, $values), 'after' => $values]);

        return $this->settings();
    }

    public function workspaceSettings(string $workspace): JsonResponse
    {
        $ws = $this->workspace($workspace);

        return new JsonResponse(['data' => ['workspace' => ['id' => $ws->id, 'name' => $ws->name], 'values' => $this->settings->all($ws->id),
            'overrides' => DB::table('writer_settings')->where('workspace_id', $ws->id)->value('values') !== null
                ? json_decode((string) DB::table('writer_settings')->where('workspace_id', $ws->id)->value('values'), true) : [],
            'workspace_keys' => WriterSettings::WORKSPACE_KEYS]]);
    }

    public function updateWorkspaceSettings(UpdateWriterSettingsRequest $request, string $workspace): JsonResponse
    {
        $ws = $this->workspace($workspace);
        $values = array_intersect_key($request->validated(), array_flip(WriterSettings::WORKSPACE_KEYS));
        $this->assertBudgetOrder($values, $ws->id);
        $this->settings->save($ws->id, $values, (string) $request->user()?->getAuthIdentifier());
        Audit::record('admin.writer.workspace_settings_updated', null, ['values' => $values], $ws->id);

        return $this->workspaceSettings($workspace);
    }

    public function routing(): JsonResponse
    {
        $keys = WriterAi::TASKS;
        $routes = AiTaskRoute::query()->with(['primaryModel', 'economyModel'])->whereIn('task_key', $keys)->get()->keyBy('task_key');

        return new JsonResponse(['data' => array_map(fn (string $k) => ($r = $routes->get($k)) === null ? ['task_key' => $k, 'configured' => false] : [
            'task_key' => $k, 'configured' => true, 'route_id' => $r->id, 'is_enabled' => $r->is_enabled, 'queue' => $r->queue,
            'primary_model' => $r->primaryModel?->only(['id', 'model_key', 'display_name']), 'economy_model' => $r->economyModel?->only(['id', 'model_key', 'display_name']),
            'fallback_model_ids' => $r->fallback_model_ids ?? [], 'max_output_tokens' => $r->max_output_tokens, 'max_attempts' => $r->max_attempts,
            'allow_paid_retry' => $r->allow_paid_retry,
        ], $keys)]);
    }

    public function costs(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->integer('days', 30)));
        $since = now()->subDays($days)->startOfDay();

        // Reviewed scope bypass: platform-wide read-only aggregates.
        return $this->context->bypass(function () use ($since, $days): JsonResponse {
            $base = fn () => DB::table('article_ai_usage')->where('created_at', '>=', $since);
            $byTask = $base()->selectRaw('task_key, count(*) as calls, sum(cost_micro_usd) as cost, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
                ->groupBy('task_key')->orderByDesc('cost')->get();
            $byModel = $base()->selectRaw('model, count(*) as calls, sum(cost_micro_usd) as cost')->groupBy('model')->orderByDesc('cost')->get();
            $byWorkspace = $base()->selectRaw('workspace_id, count(distinct article_id) as articles, sum(cost_micro_usd) as cost')->groupBy('workspace_id')->orderByDesc('cost')->limit(20)->get();
            $names = Workspace::query()->whereIn('id', $byWorkspace->pluck('workspace_id'))->pluck('name', 'id');
            $byDay = $base()->selectRaw('date(created_at) as day, sum(cost_micro_usd) as cost, count(distinct article_id) as articles')->groupBy('day')->orderBy('day')->get();
            $articles = DB::table('articles')->where('created_at', '>=', $since)->where('spent_micro_usd', '>', 0);

            return new JsonResponse(['data' => [
                'days' => $days,
                'total_cost_micro_usd' => (int) $base()->sum('cost_micro_usd'),
                'articles' => (int) (clone $articles)->count(),
                'avg_cost_per_article_micro_usd' => (int) round((float) (clone $articles)->avg('spent_micro_usd')),
                'by_task' => $byTask->map(fn ($r) => ['task_key' => $r->task_key, 'calls' => (int) $r->calls, 'cost_micro_usd' => (int) $r->cost, 'input_tokens' => (int) $r->input_tokens, 'output_tokens' => (int) $r->output_tokens]),
                'by_model' => $byModel->map(fn ($r) => ['model' => $r->model, 'calls' => (int) $r->calls, 'cost_micro_usd' => (int) $r->cost]),
                'by_workspace' => $byWorkspace->map(fn ($r) => ['workspace_id' => $r->workspace_id, 'name' => $names[$r->workspace_id] ?? null, 'articles' => (int) $r->articles, 'cost_micro_usd' => (int) $r->cost]),
                'by_day' => $byDay->map(fn ($r) => ['day' => (string) $r->day, 'cost_micro_usd' => (int) $r->cost, 'articles' => (int) $r->articles]),
            ]]);
        });
    }

    public function runs(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return $this->context->bypass(function () use ($request, $status): JsonResponse {
            $q = WorkflowRun::query()->whereIn('type', self::TYPES);
            if (is_string($status) && in_array($status, ['pending', 'running', 'retry_scheduled', 'succeeded', 'failed', 'canceled'], true)) {
                $q->where('status', $status);
            }
            $type = $request->query('type');
            if (is_string($type) && in_array($type, self::TYPES, true)) {
                $q->where('type', $type);
            }
            $perPage = max(1, min(100, (int) $request->integer('per_page', 30)));
            $page = $q->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($perPage);
            $links = DB::table('article_workflow_runs')->whereIn('workflow_run_id', collect($page->items())->pluck('id'))->pluck('article_id', 'workflow_run_id');
            $names = Workspace::query()->whereIn('id', collect($page->items())->pluck('workspace_id')->unique())->pluck('name', 'id');

            return new JsonResponse([
                'data' => array_map(fn (WorkflowRun $r) => ['id' => $r->id, 'type' => $r->type, 'status' => $r->status->value, 'current_step' => $r->current_step,
                    'attempts' => $r->attempts, 'cycle' => $r->cycle, 'workspace_id' => $r->workspace_id, 'workspace_name' => $names[$r->workspace_id] ?? null,
                    'article_id' => $links[$r->id] ?? null, 'error' => $r->error_code !== null ? ['code' => $r->error_code, 'message' => $r->error_message] : null,
                    'started_at' => $r->started_at?->toIso8601ZuluString(), 'finished_at' => $r->finished_at?->toIso8601ZuluString(), 'created_at' => $r->created_at->toIso8601ZuluString()], $page->items()),
                'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'per_page' => $perPage],
                'summary' => WorkflowRun::query()->whereIn('type', self::TYPES)->where('created_at', '>=', now()->subDay())
                    ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            ]);
        });
    }

    public function failures(Request $request): JsonResponse
    {
        $since = now()->subDays(max(1, min(90, (int) $request->integer('days', 7))));

        return $this->context->bypass(fn (): JsonResponse => new JsonResponse(['data' => [
            'workflow_errors' => WorkflowRun::query()->whereIn('type', self::TYPES)->where('status', 'failed')->where('created_at', '>=', $since)
                ->selectRaw('type, error_code, count(*) as n')->groupBy('type', 'error_code')->orderByDesc('n')->limit(50)->get()
                ->map(fn ($r) => ['type' => $r->type, 'error_code' => $r->error_code, 'count' => (int) $r->getAttribute('n')]),
            'ai_call_errors' => DB::table('article_ai_usage')->where('status', 'failed')->where('created_at', '>=', $since)
                ->selectRaw('task_key, error_code, count(*) as n')->groupBy('task_key', 'error_code')->orderByDesc('n')->limit(50)->get()
                ->map(fn ($r) => ['task_key' => $r->task_key, 'error_code' => $r->error_code, 'count' => (int) $r->n]),
            'fact_check_partial_runs' => (int) DB::table('fact_check_runs')->where('status', 'partial')->where('created_at', '>=', $since)->count(),
            'source_retrieval' => DB::table('evidence_sources')->where('created_at', '>=', $since)
                ->selectRaw('retrieval_status, count(*) as n')->groupBy('retrieval_status')->pluck('n', 'retrieval_status')->map(fn ($n) => (int) $n),
            'verdicts' => DB::table('fact_check_results')->where('created_at', '>=', $since)
                ->selectRaw('verdict, reviewer_type, count(*) as n')->groupBy('verdict', 'reviewer_type')->get()
                ->map(fn ($r) => ['verdict' => $r->verdict, 'reviewer_type' => $r->reviewer_type, 'count' => (int) $r->n]),
            'quality' => [
                'avg_score' => round((float) DB::table('quality_reports')->where('created_at', '>=', $since)->avg('score'), 1),
                'below_policy' => (int) DB::table('quality_reports')->where('created_at', '>=', $since)->where('score', '<', $this->settings->int('min_quality_score_for_approval'))->count(),
            ],
        ]]));
    }

    public function abuse(): JsonResponse
    {
        $perHour = $this->settings->int('abuse_articles_per_hour');
        $costDay = $this->settings->int('abuse_cost_per_day_micro_usd');
        $failedPct = $this->settings->int('abuse_failed_ratio_pct');

        return $this->context->bypass(function () use ($perHour, $costDay, $failedPct): JsonResponse {
            $signals = [];
            foreach (DB::table('articles')->where('created_at', '>=', now()->subHour())->selectRaw('workspace_id, count(*) as n')->groupBy('workspace_id')->havingRaw('count(*) >= ?', [$perHour])->get() as $r) {
                $signals[] = ['workspace_id' => $r->workspace_id, 'signal' => 'article_burst', 'value' => (int) $r->n, 'threshold' => $perHour];
            }
            foreach (DB::table('article_ai_usage')->where('created_at', '>=', now()->subDay())->selectRaw('workspace_id, sum(cost_micro_usd) as c')->groupBy('workspace_id')->havingRaw('sum(cost_micro_usd) >= ?', [$costDay])->get() as $r) {
                $signals[] = ['workspace_id' => $r->workspace_id, 'signal' => 'cost_spike', 'value' => (int) $r->c, 'threshold' => $costDay];
            }
            foreach (WorkflowRun::query()->whereIn('type', self::TYPES)->where('created_at', '>=', now()->subDay())
                ->selectRaw("workspace_id, count(*) as n, sum(case when status = 'failed' then 1 else 0 end) as f")->groupBy('workspace_id')->havingRaw('count(*) >= 5')->get() as $r) {
                $pct = (int) round(((int) $r->getAttribute('f')) * 100 / max(1, (int) $r->getAttribute('n')));
                if ($pct >= $failedPct) {
                    $signals[] = ['workspace_id' => $r->workspace_id, 'signal' => 'high_failure_ratio', 'value' => $pct, 'threshold' => $failedPct];
                }
            }
            foreach (DB::table('evidence_sources')->where('created_at', '>=', now()->subDay())->whereIn('retrieval_status', ['blocked_robots', 'blocked_policy', 'blocked_access'])
                ->selectRaw('workspace_id, count(*) as n')->groupBy('workspace_id')->havingRaw('count(*) >= 50')->get() as $r) {
                $signals[] = ['workspace_id' => $r->workspace_id, 'signal' => 'blocked_source_attempts', 'value' => (int) $r->n, 'threshold' => 50];
            }
            $names = Workspace::query()->whereIn('id', array_column($signals, 'workspace_id'))->pluck('name', 'id');

            return new JsonResponse(['data' => array_map(fn ($s) => $s + ['workspace_name' => $names[$s['workspace_id']] ?? null], $signals)]);
        });
    }

    private function workspace(string $id): Workspace
    {
        if (preg_match('/^[0-9a-z]{26}$/i', $id) !== 1) {
            throw ApiException::notFound();
        }

        return Workspace::query()->find($id) ?? throw ApiException::notFound();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertBudgetOrder(array $values, ?string $workspaceId): void
    {
        $merged = array_merge($this->settings->all($workspaceId), $values);
        if ((int) $merged['default_article_budget_micro_usd'] > (int) $merged['max_article_budget_micro_usd']) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['default_article_budget_micro_usd' => [__('writer.errors.default_above_max')]]);
        }
    }
}
