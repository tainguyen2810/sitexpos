<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Http\Requests\AgentPolicyRequest;
use SiteXPost\Modules\Agent\Support\AgentSettings;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;
use SiteXPost\Modules\Audit\Audit;

/**
 * Super Admin: global agent policy and kill switch, run monitor, failing
 * runs, tool usage. The kill switch blocks every new run and every task at
 * its next policy check (before execution).
 */
final class AdminAgentController
{
    public function __construct(private readonly TenantContext $context) {}

    public function overview(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->integer('days', 7)));
        $since = now()->subDays($days);

        return $this->context->bypass(fn (): JsonResponse => new JsonResponse(['data' => [
            'days' => $days,
            'runs' => DB::table('agent_runs')->where('created_at', '>=', $since)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'active_runs' => DB::table('agent_runs')->whereIn('status', ['queued', 'running'])->count(),
            'tasks_by_tool' => DB::table('agent_tasks')->where('created_at', '>=', $since)->selectRaw('tool, status, count(*) as n')->groupBy('tool', 'status')->get(),
            'decisions' => DB::table('agent_tasks')->where('created_at', '>=', $since)->selectRaw('decision, count(*) as n')->groupBy('decision')->pluck('n', 'decision'),
            'failed_runs' => DB::table('agent_runs')->where('status', 'failed')->orderByDesc('updated_at')->limit(50)
                ->get(['id', 'workspace_id', 'site_id', 'kind', 'error_code', 'updated_at']),
            'levels' => DB::table('agent_configs')->where('enabled', true)->selectRaw('autonomy_level, count(*) as n')->groupBy('autonomy_level')->pluck('n', 'autonomy_level'),
            'emergency_stops' => DB::table('agent_configs')->where('emergency_stop', true)->count(),
            'estimated_cost_micro_usd' => (int) DB::table('agent_runs')->where('created_at', '>=', $since)->sum('estimated_cost_micro_usd'),
        ]]));
    }

    public function policy(): JsonResponse
    {
        return new JsonResponse(['data' => AgentSettings::platform(), 'meta' => ['tools' => ToolRegistry::describe()]]);
    }

    public function updatePolicy(AgentPolicyRequest $request): JsonResponse
    {
        $p = AgentSettings::platform();
        $d = $request->validated();
        if ((int) $d['lock_version'] !== $p->lock_version) {
            throw ApiException::conflict('version_conflict', __('agent.errors.config_conflict'));
        }
        $fill = array_intersect_key($d, array_flip(['kill_switch', 'kill_reason', 'max_autonomy_level', 'forbidden_tools', 'max_tasks_per_run', 'max_concurrent_runs',
            'task_timeout_seconds', 'run_timeout_minutes']));
        $p->forceFill($fill + ['lock_version' => $p->lock_version + 1, 'updated_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        Audit::record(($fill['kill_switch'] ?? null) === true ? 'agent.platform_kill_switch' : 'agent.platform_policy_updated', $p, ['fields' => array_keys($fill)]);

        return new JsonResponse(['data' => $p->refresh()]);
    }
}
