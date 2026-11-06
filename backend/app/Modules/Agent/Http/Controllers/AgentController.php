<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Domain\AgentActionLog;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentMemory;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Domain\AutomationSchedule;
use SiteXPost\Modules\Agent\Engine\AgentOrchestrator;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Agent\Http\AgentPresenter;
use SiteXPost\Modules\Agent\Http\Requests\EmergencyStopRequest;
use SiteXPost\Modules\Agent\Http\Requests\ScheduleRequest;
use SiteXPost\Modules\Agent\Http\Requests\StartRunRequest;
use SiteXPost\Modules\Agent\Http\Requests\TaskDecisionRequest;
use SiteXPost\Modules\Agent\Http\Requests\UpdateAgentConfigRequest;
use SiteXPost\Modules\Agent\Support\AgentLog;
use SiteXPost\Modules\Agent\Support\AgentSettings;
use SiteXPost\Modules\Agent\Support\SiteRef;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * SEO Agent: configuration, runs, approval queue, logs, schedules, memory.
 * The agent itself never calls these endpoints: they are for people.
 */
final class AgentController
{
    public function __construct(private readonly AgentOrchestrator $orchestrator, private readonly TenantContext $context) {}

    public function tools(Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);

        return new JsonResponse(['data' => ToolRegistry::describe(), 'meta' => ['pipeline_steps' => ToolRegistry::PIPELINE_STEPS,
            'levels' => [0 => __('agent.levels.0'), 1 => __('agent.levels.1'), 2 => __('agent.levels.2'), 3 => __('agent.levels.3')]]]);
    }

    public function config(Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);
        $s = SiteRef::resolve($site);

        return new JsonResponse(['data' => AgentPresenter::config(AgentSettings::site($s['id']), AgentSettings::platform())]);
    }

    public function updateConfig(UpdateAgentConfigRequest $request, Workspace $workspace, string $site, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('configure', AgentConfig::class);
        $siteId = SiteRef::resolve($site)['id'];
        $c = AgentSettings::site($siteId);
        $d = $request->validated();
        if ((int) $d['lock_version'] !== $c->lock_version) {
            throw ApiException::conflict('version_conflict', __('agent.errors.config_conflict'));
        }
        $userId = (string) $request->user()?->getAuthIdentifier();
        $fill = array_intersect_key($d, array_flip(['enabled', 'autonomy_level', 'allowed_actions', 'forbidden_actions', 'max_daily_tasks', 'max_monthly_ai_spend_micro_usd',
            'max_monthly_refreshes', 'publish_window_start', 'publish_window_end', 'timezone', 'approval_requirements']));
        $level = (int) ($fill['autonomy_level'] ?? $c->autonomy_level);
        if ($level === 3 && $c->level3_enabled_by === null) {
            // Level 3 is never switched on implicitly: an explicit confirmation by a person who manages sites.
            if (! (bool) ($d['confirm_level3'] ?? false)) {
                throw ApiException::conflict('level3_confirmation_required', __('agent.errors.level3_confirmation_required'));
            }
            $fill['level3_enabled_by'] = $userId;
            $fill['level3_enabled_at'] = now();
        }
        if ($level < 3) {
            $fill['level3_enabled_by'] = null;
            $fill['level3_enabled_at'] = null;
        }
        if (($fill['enabled'] ?? false) === true && ! $c->enabled) {
            $entitlements->assertActive($this->context->workspaceId(), 'automation');
        }
        $before = array_intersect_key($c->getAttributes(), $fill);
        $c->forceFill($fill + ['principal_user_id' => $userId, 'updated_by' => $userId, 'lock_version' => $c->lock_version + 1])->save();
        Audit::record('agent.config_updated', $c, ['fields' => array_keys($fill), 'autonomy_level' => $c->autonomy_level, 'enabled' => $c->enabled]);
        AgentLog::record('config.updated', 'Cấu hình agent được cập nhật', $siteId, null, null, ['fields' => array_keys($fill), 'before' => $before], 'user', $userId);

        return new JsonResponse(['data' => AgentPresenter::config($c->refresh(), AgentSettings::platform())]);
    }

    public function emergencyStop(EmergencyStopRequest $request, Workspace $workspace, string $site): JsonResponse
    {
        $siteId = SiteRef::resolve($site)['id'];
        $stop = (bool) $request->validated('stop');
        // Anyone who edits content may STOP the agent; resuming needs sites.manage.
        Gate::authorize($stop ? 'operate' : 'configure', AgentConfig::class);
        $c = AgentSettings::site($siteId);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $c->forceFill(['emergency_stop' => $stop, 'updated_by' => $userId, 'lock_version' => $c->lock_version + 1])->save();
        if ($stop) {
            foreach (AgentRun::query()->where('site_id', $siteId)->whereIn('status', ['queued', 'running', 'paused', 'awaiting_approval'])->get() as $run) {
                $this->orchestrator->cancel($run, $userId, 'emergency_stop');
            }
        }
        Audit::record($stop ? 'agent.emergency_stop' : 'agent.emergency_stop_cleared', $c, ['reason' => $request->validated('reason')]);
        AgentLog::record($stop ? 'emergency_stop' : 'emergency_stop_cleared', (string) ($request->validated('reason') ?? ''), $siteId, null, null, [], 'user', $userId, 'warning');

        return new JsonResponse(['data' => AgentPresenter::config($c->refresh(), AgentSettings::platform())]);
    }

    public function startRun(StartRunRequest $request, Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('operate', AgentRun::class);
        $siteId = SiteRef::resolve($site)['id'];
        $key = $request->validated('idempotency_key');
        $run = $this->orchestrator->start($siteId, (string) ($request->validated('kind') ?? 'daily_monitoring'), 'manual', (string) $request->user()?->getAuthIdentifier(), null,
            $key !== null ? 'manual:'.$siteId.':'.$key : null);

        return new JsonResponse(['data' => AgentPresenter::run($run->refresh())], 202);
    }

    public function runs(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);
        $q = AgentRun::query()->orderByDesc('created_at')->orderByDesc('id')->limit(100);
        $this->siteFilter($q, $request);
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['queued', 'running', 'paused', 'awaiting_approval', 'completed', 'failed', 'cancelled'], true)) {
            $q->where('status', $status);
        }

        return new JsonResponse(['data' => $q->get()->map(fn ($r) => AgentPresenter::run($r))->all()]);
    }

    public function run(Workspace $workspace, AgentRun $agentRun): JsonResponse
    {
        Gate::authorize('view', $agentRun);

        return new JsonResponse(['data' => AgentPresenter::run($agentRun)
            + ['tasks' => AgentTask::query()->where('agent_run_id', $agentRun->id)->orderBy('sequence')->get()->map(fn ($t) => AgentPresenter::task($t))->all()]]);
    }

    public function pauseRun(Request $request, Workspace $workspace, AgentRun $agentRun): JsonResponse
    {
        Gate::authorize('operate', $agentRun);

        return new JsonResponse(['data' => AgentPresenter::run($this->orchestrator->pause($agentRun, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function resumeRun(Request $request, Workspace $workspace, AgentRun $agentRun): JsonResponse
    {
        Gate::authorize('operate', $agentRun);

        return new JsonResponse(['data' => AgentPresenter::run($this->orchestrator->resume($agentRun, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function cancelRun(Request $request, Workspace $workspace, AgentRun $agentRun): JsonResponse
    {
        Gate::authorize('operate', $agentRun);

        return new JsonResponse(['data' => AgentPresenter::run($this->orchestrator->cancel($agentRun, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function tasks(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);
        $q = AgentTask::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200);
        $this->siteFilter($q, $request);
        $status = (string) $request->query('status', 'awaiting_approval');
        if (in_array($status, ['pending', 'awaiting_approval', 'approved', 'queued', 'running', 'succeeded', 'failed', 'rejected', 'cancelled', 'skipped', 'blocked'], true)) {
            $q->where('status', $status);
        }

        return new JsonResponse(['data' => $q->get()->map(fn ($t) => AgentPresenter::task($t))->all()]);
    }

    public function approveTask(TaskDecisionRequest $request, Workspace $workspace, AgentTask $agentTask): JsonResponse
    {
        Gate::authorize('operate', $agentTask);

        return new JsonResponse(['data' => AgentPresenter::task($this->orchestrator->approveTask($agentTask, (string) $request->user()?->getAuthIdentifier(), $request->validated('note')))]);
    }

    public function rejectTask(TaskDecisionRequest $request, Workspace $workspace, AgentTask $agentTask): JsonResponse
    {
        Gate::authorize('operate', $agentTask);

        return new JsonResponse(['data' => AgentPresenter::task($this->orchestrator->rejectTask($agentTask, (string) $request->user()?->getAuthIdentifier(), $request->validated('note')))]);
    }

    public function retryTask(Request $request, Workspace $workspace, AgentTask $agentTask): JsonResponse
    {
        Gate::authorize('operate', $agentTask);

        return new JsonResponse(['data' => AgentPresenter::task($this->orchestrator->retryTask($agentTask, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function logs(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);
        $q = AgentActionLog::query()->orderByDesc('created_at')->orderByDesc('id')->limit(300);
        $this->siteFilter($q, $request);
        $run = $request->query('run_id');
        if (is_string($run) && preg_match('/^[0-9a-z]{26}$/i', $run) === 1) {
            $q->where('agent_run_id', $run);
        }

        return new JsonResponse(['data' => $q->get()->map(fn ($l) => AgentPresenter::log($l))->all()]);
    }

    public function memories(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);
        $q = AgentMemory::query()->where(fn ($b) => $b->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('created_at')->limit(200);
        $this->siteFilter($q, $request);

        return new JsonResponse(['data' => $q->get()->map(fn ($m) => AgentPresenter::memory($m))->all()]);
    }

    public function schedules(Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('viewAny', AgentRun::class);
        $siteId = SiteRef::resolve($site)['id'];

        return new JsonResponse(['data' => AutomationSchedule::query()->where('site_id', $siteId)->orderBy('kind')->get()->map(fn ($s) => AgentPresenter::schedule($s))->all(),
            'meta' => ['kinds' => ScheduleCalculator::KINDS]]);
    }

    public function storeSchedule(ScheduleRequest $request, Workspace $workspace, string $site, Entitlements $entitlements): JsonResponse
    {
        Gate::authorize('configure', AgentConfig::class);
        $siteId = SiteRef::resolve($site)['id'];
        $d = $request->validated();
        $s = AutomationSchedule::query()->where('site_id', $siteId)->where('kind', $d['kind'])->first();
        if ($s === null) {
            $entitlements->assertWithin($this->context->workspaceId(), 'automation_schedules', AutomationSchedule::query()->where('status', '!=', 'disabled')->count());
            $s = new AutomationSchedule;
            $s->forceFill(['site_id' => $siteId, 'kind' => $d['kind'], 'frequency' => ScheduleCalculator::KINDS[$d['kind']], 'timezone' => AgentSettings::site($siteId)->timezone,
                'created_by' => (string) $request->user()?->getAuthIdentifier()]);
        }
        $this->fillSchedule($s, $d);
        $s->forceFill(['status' => $s->status ?? 'active'])->save();
        Audit::record('agent.schedule_saved', $s, ['kind' => $s->kind, 'frequency' => $s->frequency, 'local_time' => $s->local_time]);

        return new JsonResponse(['data' => AgentPresenter::schedule($s->refresh())], 201);
    }

    public function updateSchedule(ScheduleRequest $request, Workspace $workspace, AutomationSchedule $automationSchedule): JsonResponse
    {
        Gate::authorize('configure', $automationSchedule);
        $d = $request->validated();
        if (isset($d['status'])) {
            $automationSchedule->forceFill(['status' => $d['status']]);
        }
        $this->fillSchedule($automationSchedule, $d);
        $automationSchedule->save();
        Audit::record('agent.schedule_updated', $automationSchedule, ['status' => $automationSchedule->status]);

        return new JsonResponse(['data' => AgentPresenter::schedule($automationSchedule->refresh())]);
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function fillSchedule(AutomationSchedule $s, array $d): void
    {
        $s->forceFill(array_intersect_key($d, array_flip(['frequency', 'day_of_week', 'day_of_month', 'local_time', 'timezone', 'notify_on_failure'])));
        if ($s->frequency === 'weekly' && $s->day_of_week === null) {
            $s->day_of_week = 1;
        }
        if ($s->frequency === 'monthly' && $s->day_of_month === null) {
            $s->day_of_month = 1;
        }
        $s->forceFill(['next_run_at' => ($s->status ?? 'active') === 'active'
            ? ScheduleCalculator::next($s->frequency, $s->local_time ?? '07:00', $s->timezone, $s->day_of_week, $s->day_of_month, CarbonImmutable::now('UTC'))
            : null]);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $q
     */
    private function siteFilter(Builder $q, Request $request): void
    {
        $site = $request->query('site_id');
        if (is_string($site) && $site !== '') {
            $q->where('site_id', SiteRef::resolve($site)['id']);
        }
    }
}
