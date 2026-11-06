<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Engine;

use Illuminate\Database\QueryException;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Ai\PlanAdvisor;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentMemory;
use SiteXPost\Modules\Agent\Domain\AgentPolicy;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Jobs\AgentRunJob;
use SiteXPost\Modules\Agent\Jobs\AgentTaskJob;
use SiteXPost\Modules\Agent\Policy\AgentPolicyEngine;
use SiteXPost\Modules\Agent\Policy\Decision;
use SiteXPost\Modules\Agent\Support\AgentLog;
use SiteXPost\Modules\Agent\Support\AgentSettings;
use SiteXPost\Modules\Agent\Support\PolicyFacts;
use SiteXPost\Modules\Agent\Tools\ToolExecutor;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;
use SiteXPost\Modules\Analytics\Contracts\SeoInsights;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use Throwable;

/**
 * Fixed agent pipeline: Observe → Analyze → Plan → Estimate → Approve
 * (policy) → Execute → Verify → Learn. The order cannot be changed by the
 * model; every tool call is a persisted task with its policy decision.
 */
final class AgentOrchestrator
{
    public const RUN_ACTIVE = ['queued', 'running'];

    public const TASK_OPEN = ['pending', 'awaiting_approval', 'approved', 'queued', 'running'];

    public const TASK_RETRY_MINUTES = [2, 10, 30];

    public function __construct(
        private readonly AgentPolicyEngine $engine,
        private readonly PolicyFacts $facts,
        private readonly Planner $planner,
        private readonly PlanAdvisor $advisor,
        private readonly ToolExecutor $executor,
        private readonly SeoInsights $insights,
        private readonly TenantContext $context,
    ) {}

    public function start(string $siteId, string $kind, string $trigger, ?string $userId, ?string $scheduleId = null, ?string $idempotencyKey = null): AgentRun
    {
        if ($idempotencyKey !== null && ($existing = AgentRun::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }
        $platform = AgentSettings::platform();
        $config = AgentSettings::site($siteId);
        $stop = $this->stopReason($platform, $config);
        if ($stop !== null) {
            throw ApiException::conflict('agent_'.$stop, __('agent.errors.'.$stop));
        }
        $running = $this->context->bypass(fn () => AgentRun::query()->whereIn('status', self::RUN_ACTIVE)->count());
        if ($running >= $platform->max_concurrent_runs) {
            throw ApiException::conflict('agent_busy', __('agent.errors.busy'));
        }
        $run = new AgentRun;
        $run->forceFill([
            'site_id' => $siteId, 'schedule_id' => $scheduleId, 'trigger' => $trigger, 'kind' => $kind, 'status' => 'queued', 'phase' => 'observe',
            'active_key' => $siteId, 'autonomy_level' => AgentPolicyEngine::effectiveLevel($config, $platform),
            'policy_snapshot' => AgentPolicyEngine::snapshot($config, $platform), 'max_tasks' => max(1, min($platform->max_tasks_per_run, 50)),
            'deadline_at' => now()->addMinutes($platform->run_timeout_minutes), 'scheduled_for' => now(), 'created_by' => $userId, 'idempotency_key' => $idempotencyKey,
        ]);
        try {
            $run->save();
        } catch (QueryException) {
            if ($idempotencyKey !== null && ($existing = AgentRun::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }
            throw ApiException::conflict('agent_run_active', __('agent.errors.run_active'));
        }
        AgentLog::record('run.created', "Run {$kind} ({$trigger})", $siteId, $run->id, null, ['level' => $run->autonomy_level], $userId !== null ? 'user' : 'system', $userId);
        AgentRunJob::dispatch($run->id);

        return $run;
    }

    public function process(AgentRun $run): void
    {
        if (! in_array($run->status, self::RUN_ACTIVE, true)) {
            return;
        }
        $platform = AgentSettings::platform();
        $config = AgentSettings::site($run->site_id);
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        try {
            // 1 Observe (read-only tools, logged as tasks)
            $this->phase($run, 'observe');
            if ($this->halt($run, $platform, $config)) {
                return;
            }
            $this->inline($run, $config, $platform, 'analyze_site', []);
            $obs = $this->inline($run, $config, $platform, 'read_analytics', []);
            if ($obs === null) {
                $this->finish($run, 'failed', 'observe_failed');

                return;
            }
            $run->forceFill(['observations' => array_intersect_key($obs, array_flip(['data_latest', 'health', 'open_opportunities', 'decay_open']))])->save();

            // 2 Analyze + 3 Plan
            $this->phase($run, 'analyze');
            $candidates = $this->planner->candidates($run->site_id, $run->kind, $obs);
            $this->phase($run, 'plan');
            $ranked = $this->advisor->rank($candidates, $run->id);
            $plan = array_slice($ranked['candidates'], 0, $run->max_tasks);
            $run->forceFill(['plan' => array_map(fn ($c) => ['key' => $c['key'], 'tool' => $c['tool'], 'reason' => $c['reason'], 'ai_rationale' => $c['ai_rationale'] ?? null], $plan),
                'summary' => ['planning' => $ranked['mode'], 'planning_cost_micro_usd' => $ranked['cost_micro_usd'], 'candidates' => count($candidates)]])->save();

            // 4 Estimate
            $this->phase($run, 'estimate');
            $estimate = array_sum(array_map(fn ($c) => ToolRegistry::get($c['tool'])['cost'], $plan));
            $run->forceFill(['estimated_cost_micro_usd' => $estimate])->save();

            // 5 Approve (policy engine) — creates one task per planned call
            $this->phase($run, 'approve');
            if ($this->halt($run, $platform, $config)) {
                return;
            }
            $seq = AgentTask::query()->where('agent_run_id', $run->id)->max('sequence') ?? 0;
            foreach ($plan as $c) {
                $this->createTask($run, $config, $platform, $c['tool'], $c['input'], ['reason' => $c['reason']] + $c['evidence'], ++$seq);
            }

            // 6 Execute
            $this->phase($run, 'execute');
            foreach (AgentTask::query()->where('agent_run_id', $run->id)->where('status', 'queued')->orderBy('sequence')->pluck('id') as $id) {
                AgentTaskJob::dispatch($id);
            }
            $this->settle($run->refresh());
        } catch (Throwable $e) {
            report($e);
            $this->finish($run, 'failed', 'server_error');
        }
    }

    /**
     * Policy decision for one proposed call → persisted task.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $evidence
     */
    public function createTask(AgentRun $run, AgentConfig $config, AgentPolicy $platform, string $tool, array $input, array $evidence, int $seq): AgentTask
    {
        $d = $this->engine->evaluate($tool, $config, $platform, $this->facts->for($tool, $config));
        $task = new AgentTask;
        $task->forceFill([
            'site_id' => $run->site_id, 'agent_run_id' => $run->id, 'sequence' => $seq, 'tool' => $tool,
            'status' => match ($d->decision) {
                Decision::ALLOW => 'queued', Decision::REQUIRE_APPROVAL => 'awaiting_approval', default => 'blocked'
            },
            'decision' => $d->decision, 'decision_reasons' => $d->reasons, 'input' => $input, 'evidence' => $evidence,
            'policy_snapshot' => AgentPolicyEngine::snapshot($config, $platform), 'estimated_cost_micro_usd' => ToolRegistry::exists($tool) ? ToolRegistry::get($tool)['cost'] : 0,
            'max_attempts' => 3, 'idempotency_key' => $run->id.':'.$seq.':'.$tool,
        ])->save();
        AgentLog::record('task.decided', "{$tool}: {$d->decision}", $run->site_id, $run->id, $task->id, ['reasons' => $d->reasons],
            level: $d->decision === Decision::DENY ? 'warning' : 'info');

        return $task;
    }

    public function execute(AgentTask $task): void
    {
        if ($task->status !== 'queued' || ($task->next_attempt_at !== null && $task->next_attempt_at->isFuture())) {
            return;
        }
        $run = AgentRun::query()->find($task->agent_run_id);
        if ($run === null || in_array($run->status, ['paused', 'cancelled', 'failed'], true)) {
            return; // paused runs keep their queued tasks; resume re-dispatches them
        }
        $platform = AgentSettings::platform();
        $config = AgentSettings::site($task->site_id);
        // Policy is re-evaluated right before execution (kill switch, budget, principal, window).
        $d = $this->engine->evaluate($task->tool, $config, $platform, $this->facts->for($task->tool, $config));
        $humanApproved = $task->approved_by !== null;
        if ($d->decision === Decision::DENY || ($d->decision === Decision::REQUIRE_APPROVAL && ! $humanApproved)) {
            $task->forceFill(['status' => $d->decision === Decision::DENY ? 'blocked' : 'awaiting_approval', 'decision' => $d->decision, 'decision_reasons' => $d->reasons])->save();
            AgentLog::record('task.blocked_at_execution', "{$task->tool}: {$d->decision}", $task->site_id, $task->agent_run_id, $task->id, ['reasons' => $d->reasons], level: 'warning');
            $this->settle($run);

            return;
        }
        $claimed = AgentTask::query()->whereKey($task->id)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(), 'attempts' => $task->attempts + 1, 'timeout_at' => now()->addSeconds($platform->task_timeout_seconds),
        ]);
        if ($claimed === 0) {
            return; // another worker took it (idempotent execution)
        }
        $task->refresh();
        try {
            $out = $this->executor->run($task, $config);
            $task->forceFill(['status' => 'succeeded', 'output' => $out, 'finished_at' => now(), 'error_code' => null, 'error_message' => null])->save();
            AgentLog::record('task.succeeded', $task->tool, $task->site_id, $task->agent_run_id, $task->id, ['output_keys' => array_keys($out)]);
            if (ToolRegistry::get($task->tool)['writes']) {
                Audit::record('agent.task_executed', $task, ['tool' => $task->tool, 'principal' => $config->principal_user_id, 'approved_by' => $task->approved_by]);
            }
        } catch (ApiException $e) {
            $task->forceFill(['status' => 'failed', 'error_code' => mb_substr($e->errorCode, 0, 64), 'error_message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();
            AgentLog::record('task.failed', "{$task->tool}: {$e->errorCode}", $task->site_id, $task->agent_run_id, $task->id, level: 'error');
        } catch (Throwable $e) {
            report($e);
            $retry = $task->attempts < $task->max_attempts;
            $task->forceFill(['status' => $retry ? 'queued' : 'failed', 'error_code' => 'server_error', 'error_message' => __('api.server_error'),
                'next_attempt_at' => $retry ? now()->addMinutes(self::TASK_RETRY_MINUTES[min($task->attempts - 1, 2)]) : null, 'finished_at' => $retry ? null : now()])->save();
            AgentLog::record('task.error', $task->tool.($retry ? ' (retry scheduled)' : ''), $task->site_id, $task->agent_run_id, $task->id, level: 'error');
        }
        $this->settle($run->refresh());
    }

    /** Verify + Learn once nothing is queued or running. */
    public function settle(AgentRun $run): void
    {
        if (in_array($run->status, ['completed', 'failed', 'cancelled', 'paused'], true) || ! in_array($run->phase, ['execute', 'verify', 'learn', 'done'], true)) {
            return;
        }
        $tasks = AgentTask::query()->where('agent_run_id', $run->id)->get(['id', 'tool', 'status', 'output', 'actual_cost_micro_usd']);
        if ($tasks->whereIn('status', ['queued', 'running', 'approved', 'pending'])->isNotEmpty()) {
            return;
        }
        $this->phase($run, 'verify');
        $verify = [];
        foreach ($tasks->where('tool', 'create_refresh_brief')->where('status', 'succeeded') as $t) {
            $id = (string) ($t->output['id'] ?? '');
            $r = $id !== '' ? $this->insights->refresh($id) : null;
            $verify[] = ['task_id' => $t->id, 'refresh_job_id' => $id, 'refresh_status' => $r['status'] ?? 'missing'];
        }
        $this->phase($run, 'learn');
        $this->learn($run);
        $counts = $tasks->countBy('status')->all();
        $awaiting = ($counts['awaiting_approval'] ?? 0) > 0;
        $run->forceFill(['summary' => array_merge((array) $run->summary, ['tasks' => $counts, 'verify' => $verify])])->save();
        $this->finish($run, $awaiting ? 'awaiting_approval' : 'completed', null);
    }

    public function approveTask(AgentTask $task, string $userId, ?string $note): AgentTask
    {
        if ($task->status !== 'awaiting_approval') {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.task_not_awaiting'));
        }
        // The approver must hold the tool's permission; hard limits (kill switch, budget, forbidden, level 0) still apply.
        $member = WorkspaceMember::query()->where('workspace_id', $this->context->workspaceId())->where('user_id', $userId)->where('status', MemberStatus::Active)->first();
        if ($member === null || ! ToolRegistry::exists($task->tool) || ! $member->role->allows(ToolRegistry::permission($task->tool))) {
            throw ApiException::forbidden('approver_lacks_permission', __('agent.errors.approver_lacks_permission'));
        }
        $config = AgentSettings::site($task->site_id);
        $d = $this->engine->evaluate($task->tool, $config, AgentSettings::platform(), $this->facts->for($task->tool, $config));
        if ($d->decision === Decision::DENY) {
            throw ApiException::conflict('policy_denied', __('agent.errors.policy_denied', ['reasons' => implode(', ', $d->reasons)]));
        }
        $task->forceFill(['status' => 'queued', 'approved_by' => $userId, 'approved_at' => now()])->save();
        AgentLog::record('task.approved', $task->tool, $task->site_id, $task->agent_run_id, $task->id, ['note' => $note], 'user', $userId);
        Audit::record('agent.task_approved', $task, ['tool' => $task->tool], actorId: $userId);
        $run = AgentRun::query()->find($task->agent_run_id);
        if ($run !== null && $run->status === 'awaiting_approval') {
            $run->forceFill(['status' => 'running'])->save();
        }
        AgentTaskJob::dispatch($task->id);

        return $task->refresh();
    }

    public function rejectTask(AgentTask $task, string $userId, ?string $note): AgentTask
    {
        if (! in_array($task->status, ['awaiting_approval', 'queued'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.task_not_awaiting'));
        }
        $task->forceFill(['status' => 'rejected', 'finished_at' => now(), 'error_message' => $note !== null ? mb_substr($note, 0, 500) : null])->save();
        AgentLog::record('task.rejected', $task->tool, $task->site_id, $task->agent_run_id, $task->id, ['note' => $note], 'user', $userId);
        // Learn from the human decision (verified feedback).
        if (isset($task->input['article_id']) && is_string($task->input['article_id'])) {
            $this->remember($task->site_id, 'user_feedback', 'Người dùng từ chối '.$task->tool.' cho bài này.', ['task_id' => $task->id, 'note' => $note],
                'article:'.$task->input['article_id'], true, 90);
        }
        $run = AgentRun::query()->find($task->agent_run_id);
        if ($run !== null && $run->status === 'awaiting_approval') {
            $run->forceFill(['status' => 'running'])->save();
            $this->settle($run);
        }

        return $task->refresh();
    }

    public function retryTask(AgentTask $task, string $userId): AgentTask
    {
        if (! in_array($task->status, ['failed', 'blocked'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.task_not_retryable'));
        }
        $run = AgentRun::query()->find($task->agent_run_id);
        if ($run === null || in_array($run->status, ['cancelled'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.task_not_retryable'));
        }
        $config = AgentSettings::site($task->site_id);
        $d = $this->engine->evaluate($task->tool, $config, AgentSettings::platform(), $this->facts->for($task->tool, $config));
        $status = match ($d->decision) {
            Decision::ALLOW => 'queued', Decision::REQUIRE_APPROVAL => $task->approved_by !== null ? 'queued' : 'awaiting_approval', default => 'blocked'
        };
        $task->forceFill(['status' => $status, 'decision' => $d->decision, 'decision_reasons' => $d->reasons, 'attempts' => 0, 'next_attempt_at' => null, 'error_code' => null, 'error_message' => null, 'finished_at' => null])->save();
        AgentLog::record('task.retry', "{$task->tool}: {$status}", $task->site_id, $task->agent_run_id, $task->id, [], 'user', $userId);
        if (in_array($run->status, ['completed', 'failed', 'awaiting_approval'], true)) {
            $run->forceFill(['status' => 'running', 'finished_at' => null])->save();
        }
        if ($status === 'queued') {
            AgentTaskJob::dispatch($task->id);
        } else {
            $this->settle($run->refresh());
        }

        return $task->refresh();
    }

    public function pause(AgentRun $run, string $userId): AgentRun
    {
        if (! in_array($run->status, ['queued', 'running', 'awaiting_approval'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.run_not_active'));
        }
        $run->forceFill(['status' => 'paused'])->save();
        AgentLog::record('run.paused', 'Paused', $run->site_id, $run->id, null, [], 'user', $userId);

        return $run;
    }

    public function resume(AgentRun $run, string $userId): AgentRun
    {
        if ($run->status !== 'paused') {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.run_not_paused'));
        }
        $stop = $this->stopReason(AgentSettings::platform(), AgentSettings::site($run->site_id));
        if ($stop !== null) {
            throw ApiException::conflict('agent_'.$stop, __('agent.errors.'.$stop));
        }
        $run->forceFill(['status' => 'running', 'deadline_at' => now()->addMinutes(AgentSettings::platform()->run_timeout_minutes)])->save();
        AgentLog::record('run.resumed', 'Resumed', $run->site_id, $run->id, null, [], 'user', $userId);
        $queued = AgentTask::query()->where('agent_run_id', $run->id)->where('status', 'queued')->pluck('id');
        if ($run->plan === null) {
            $run->forceFill(['status' => 'queued'])->save();
            AgentRunJob::dispatch($run->id);
        }
        foreach ($queued as $id) {
            AgentTaskJob::dispatch($id);
        }
        $this->settle($run->refresh());

        return $run->refresh();
    }

    public function cancel(AgentRun $run, ?string $userId, string $reason = 'cancelled_by_user'): AgentRun
    {
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            throw ApiException::conflict('invalid_state_transition', __('agent.errors.run_not_active'));
        }
        AgentTask::query()->where('agent_run_id', $run->id)->whereIn('status', ['pending', 'awaiting_approval', 'approved', 'queued'])
            ->update(['status' => 'cancelled', 'finished_at' => now(), 'error_code' => mb_substr($reason, 0, 64)]);
        $run->forceFill(['status' => 'cancelled', 'cancelled_by' => $userId, 'active_key' => null, 'finished_at' => now(), 'error_code' => mb_substr($reason, 0, 64), 'phase' => 'done'])->save();
        AgentLog::record('run.cancelled', $reason, $run->site_id, $run->id, null, [], $userId !== null ? 'user' : 'system', $userId, 'warning');

        return $run;
    }

    public function stopReason(AgentPolicy $platform, AgentConfig $config): ?string
    {
        return match (true) {
            $platform->kill_switch => 'platform_kill_switch',
            $config->emergency_stop => 'emergency_stop',
            ! $config->enabled => 'disabled',
            default => null,
        };
    }

    /**
     * Execute a read-only tool inline (observe phase) as a logged task.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function inline(AgentRun $run, AgentConfig $config, AgentPolicy $platform, string $tool, array $input): ?array
    {
        $seq = (AgentTask::query()->where('agent_run_id', $run->id)->max('sequence') ?? 0) + 1;
        $task = $this->createTask($run, $config, $platform, $tool, $input, ['reason' => 'observe'], $seq);
        if ($task->status !== 'queued') {
            return null;
        }
        $this->execute($task);
        $task->refresh();

        return $task->status === 'succeeded' ? (array) $task->output : null;
    }

    private function halt(AgentRun $run, AgentPolicy $platform, AgentConfig $config): bool
    {
        $stop = $this->stopReason($platform->refresh(), $config->refresh());
        if ($stop === null) {
            if ($run->refresh()->status === 'running') {
                return false;
            }

            return true; // paused / cancelled by a person meanwhile
        }
        $this->cancel($run, null, $stop);

        return true;
    }

    private function phase(AgentRun $run, string $phase): void
    {
        $run->forceFill(['phase' => $phase])->save();
    }

    private function finish(AgentRun $run, string $status, ?string $error): void
    {
        $run->forceFill(['status' => $status, 'phase' => 'done', 'active_key' => null, 'finished_at' => $status === 'awaiting_approval' ? null : now(),
            'error_code' => $error,
            'actual_cost_micro_usd' => (int) (((array) $run->summary)['planning_cost_micro_usd'] ?? 0) + (int) AgentTask::query()->where('agent_run_id', $run->id)->sum('actual_cost_micro_usd')])->save();
        AgentLog::record('run.'.$status, $error ?? $status, $run->site_id, $run->id, null, [], 'agent', null, $status === 'failed' ? 'error' : 'info');
    }

    /** Learn: store observations (unverified, short-lived) and finished experiment outcomes (verified). */
    private function learn(AgentRun $run): void
    {
        $o = (array) $run->observations;
        $this->remember($run->site_id, 'observation', 'Health '.($o['health'] ?? '—').', '.($o['open_opportunities'] ?? 0).' opportunity mở, '.($o['decay_open'] ?? 0).' trang nghi giảm.',
            $o, 'run:'.$run->id, false, 30);
        foreach ($this->insights->experimentOutcomes($run->site_id, now()->subDays(35)->toDateString()) as $e) {
            if (AgentMemory::query()->where('site_id', $run->site_id)->where('source_ref', 'experiment:'.$e['id'])->exists()) {
                continue;
            }
            $this->remember($run->site_id, 'experiment_outcome', 'Thay đổi '.$e['change_type'].' → '.($e['outcome'] ?? 'chưa rõ').' (so sánh trước/sau, không phải nhân quả).',
                $e, 'experiment:'.$e['id'], true, (int) config('sitex.agent.memory_retention_days', 180));
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function remember(string $siteId, string $kind, string $content, array $evidence, string $ref, bool $verified, int $days): void
    {
        $m = new AgentMemory;
        $m->forceFill(['site_id' => $siteId, 'kind' => $kind, 'content' => mb_substr($content, 0, 1000), 'evidence' => $evidence, 'source_ref' => mb_substr($ref, 0, 120),
            'verified' => $verified, 'confidence' => $verified ? 80 : 40, 'expires_at' => now()->addDays($days)])->save();
    }
}
