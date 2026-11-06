<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http;

use SiteXPost\Modules\Agent\Domain\AgentActionLog;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentMemory;
use SiteXPost\Modules\Agent\Domain\AgentPolicy;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Domain\AutomationSchedule;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Agent\Policy\AgentPolicyEngine;

final class AgentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function config(AgentConfig $c, AgentPolicy $p): array
    {
        return [
            'site_id' => $c->site_id, 'enabled' => $c->enabled, 'autonomy_level' => $c->autonomy_level, 'effective_level' => AgentPolicyEngine::effectiveLevel($c, $p),
            'emergency_stop' => $c->emergency_stop, 'allowed_actions' => $c->allowed_actions ?? [], 'forbidden_actions' => $c->forbidden_actions ?? [],
            'max_daily_tasks' => $c->max_daily_tasks, 'max_monthly_ai_spend_micro_usd' => $c->max_monthly_ai_spend_micro_usd,
            'max_monthly_refreshes' => $c->max_monthly_refreshes, 'max_monthly_new_articles' => $c->max_monthly_new_articles,
            'publish_window_start' => $c->publish_window_start, 'publish_window_end' => $c->publish_window_end, 'timezone' => $c->timezone,
            'approval_requirements' => $c->approval_requirements ?? (object) [], 'principal_user_id' => $c->principal_user_id,
            'level3_enabled' => $c->level3_enabled_by !== null, 'level3_enabled_at' => $c->level3_enabled_at?->toIso8601ZuluString(),
            'lock_version' => $c->lock_version, 'is_demo' => $c->is_demo,
            'platform' => ['kill_switch' => $p->kill_switch, 'max_autonomy_level' => $p->max_autonomy_level, 'forbidden_tools' => $p->forbidden_tools ?? [], 'max_tasks_per_run' => $p->max_tasks_per_run],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(AgentRun $r): array
    {
        return [
            'id' => $r->id, 'site_id' => $r->site_id, 'schedule_id' => $r->schedule_id, 'trigger' => $r->trigger, 'kind' => $r->kind, 'status' => $r->status,
            'phase' => $r->phase, 'autonomy_level' => $r->autonomy_level, 'max_tasks' => $r->max_tasks,
            'estimated_cost_micro_usd' => $r->estimated_cost_micro_usd, 'actual_cost_micro_usd' => $r->actual_cost_micro_usd,
            'observations' => $r->observations, 'plan' => $r->plan, 'summary' => $r->summary, 'error_code' => $r->error_code,
            'started_at' => $r->started_at?->toIso8601ZuluString(), 'finished_at' => $r->finished_at?->toIso8601ZuluString(),
            'deadline_at' => $r->deadline_at?->toIso8601ZuluString(), 'is_demo' => $r->is_demo, 'created_at' => $r->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function task(AgentTask $t): array
    {
        return [
            'id' => $t->id, 'site_id' => $t->site_id, 'agent_run_id' => $t->agent_run_id, 'sequence' => $t->sequence, 'tool' => $t->tool, 'status' => $t->status,
            'decision' => $t->decision, 'decision_reasons' => $t->decision_reasons, 'input' => $t->input, 'evidence' => $t->evidence, 'output' => $t->output,
            'estimated_cost_micro_usd' => $t->estimated_cost_micro_usd, 'actual_cost_micro_usd' => $t->actual_cost_micro_usd,
            'error_code' => $t->error_code, 'error_message' => $t->error_message, 'attempts' => $t->attempts, 'max_attempts' => $t->max_attempts,
            'approved_by' => $t->approved_by, 'approved_at' => $t->approved_at?->toIso8601ZuluString(),
            'started_at' => $t->started_at?->toIso8601ZuluString(), 'finished_at' => $t->finished_at?->toIso8601ZuluString(),
            'is_demo' => $t->is_demo, 'created_at' => $t->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(AutomationSchedule $s): array
    {
        return [
            'id' => $s->id, 'site_id' => $s->site_id, 'kind' => $s->kind, 'frequency' => $s->frequency, 'day_of_week' => $s->day_of_week, 'day_of_month' => $s->day_of_month,
            'local_time' => $s->local_time, 'timezone' => $s->timezone, 'status' => $s->status, 'next_run_at' => $s->next_run_at?->toIso8601ZuluString(),
            'last_run_at' => $s->last_run_at?->toIso8601ZuluString(), 'last_run_id' => $s->last_run_id, 'last_status' => $s->last_status,
            'consecutive_failures' => $s->consecutive_failures, 'notify_on_failure' => $s->notify_on_failure, 'is_demo' => $s->is_demo,
            'preview' => $s->status === 'active' ? ScheduleCalculator::preview($s->frequency, $s->local_time, $s->timezone, $s->day_of_week, $s->day_of_month, 3) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function log(AgentActionLog $l): array
    {
        return ['id' => $l->id, 'site_id' => $l->site_id, 'agent_run_id' => $l->agent_run_id, 'agent_task_id' => $l->agent_task_id, 'event' => $l->event, 'level' => $l->level,
            'message' => $l->message, 'context' => $l->context, 'actor_type' => $l->actor_type, 'actor_id' => $l->actor_id, 'created_at' => $l->created_at?->toIso8601ZuluString()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function memory(AgentMemory $m): array
    {
        return ['id' => $m->id, 'site_id' => $m->site_id, 'kind' => $m->kind, 'content' => $m->content, 'evidence' => $m->evidence, 'source_ref' => $m->source_ref,
            'verified' => $m->verified, 'confidence' => $m->confidence, 'expires_at' => $m->expires_at?->toIso8601ZuluString(), 'created_at' => $m->created_at?->toIso8601ZuluString()];
    }
}
