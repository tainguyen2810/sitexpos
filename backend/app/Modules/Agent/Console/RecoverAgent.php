<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Engine\AgentOrchestrator;
use SiteXPost\Modules\Agent\Jobs\AgentRunJob;
use SiteXPost\Modules\Agent\Jobs\AgentTaskJob;
use SiteXPost\Modules\Agent\Support\AgentLog;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Worker-restart safety: times out stuck tasks, re-dispatches tasks whose
 * retry delay passed and runs that never started, fails runs past their
 * deadline, prunes expired memory and old logs.
 */
final class RecoverAgent extends Command
{
    protected $signature = 'sitex:agent:recover';

    protected $description = 'Recover stuck SEO agent runs and tasks; prune agent memory';

    public function handle(TenantContext $context, AgentOrchestrator $orchestrator): int
    {
        $tasks = $context->bypass(fn () => AgentTask::query()->where(fn ($q) => $q->where(fn ($r) => $r->where('status', 'running')->where('timeout_at', '<', now()))
            ->orWhere(fn ($r) => $r->where('status', 'queued')->where(fn ($t) => $t->whereNull('next_attempt_at')->where('updated_at', '<', now()->subMinutes(10))
                ->orWhere('next_attempt_at', '<=', now()))))->limit(500)->get());
        $runs = $context->bypass(fn () => AgentRun::query()->where(fn ($q) => $q->whereIn('status', ['queued', 'running'])->where('deadline_at', '<', now()))
            ->orWhere(fn ($q) => $q->where('status', 'queued')->whereNull('started_at')->where('created_at', '<', now()->subMinutes(10)))->limit(200)->get());
        $n = 0;
        foreach ($tasks->pluck('workspace_id')->merge($runs->pluck('workspace_id'))->unique() as $workspaceId) {
            $ws = Workspace::query()->find($workspaceId);
            if ($ws === null) {
                continue;
            }
            $context->runAs($ws, null, function () use ($tasks, $runs, $workspaceId, $orchestrator, &$n): void {
                foreach ($tasks->where('workspace_id', $workspaceId) as $t) {
                    if ($t->status === 'running') {
                        $retry = $t->attempts < $t->max_attempts;
                        $t->forceFill(['status' => $retry ? 'queued' : 'failed', 'error_code' => 'timeout', 'next_attempt_at' => $retry ? now() : null, 'finished_at' => $retry ? null : now()])->save();
                        AgentLog::record('task.timeout', $t->tool, $t->site_id, $t->agent_run_id, $t->id, [], 'system', null, 'error');
                    }
                    if ($t->status === 'queued') {
                        $t->forceFill(['next_attempt_at' => null])->save();
                        AgentTaskJob::dispatch($t->id);
                    } elseif (($run = AgentRun::query()->find($t->agent_run_id)) !== null) {
                        $orchestrator->settle($run);
                    }
                    $n++;
                }
                foreach ($runs->where('workspace_id', $workspaceId) as $r) {
                    if ($r->deadline_at !== null && $r->deadline_at->isPast()) {
                        $orchestrator->cancel($r, null, 'run_timeout');
                    } else {
                        AgentRunJob::dispatch($r->id);
                    }
                    $n++;
                }
            });
        }
        $mem = DB::table('agent_memories')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        $logs = DB::table('agent_action_logs')->where('created_at', '<', now()->subDays((int) config('sitex.agent.log_retention_days', 365)))->delete();
        $this->info("Recovered {$n}; pruned {$mem} memories, {$logs} logs");

        return self::SUCCESS;
    }
}
