<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Autonomous SEO Agent (AUTONOMOUS-SEO-AGENT.md,
 * AGENT-POLICY-ENGINE.md). The agent never touches these tables' policy
 * rows itself: configs are written only by users (sites.manage) and the
 * global policy only by Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide guard rails (Super Admin). Single row, scope = 'global'.
        Schema::create('agent_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 16)->default('global');
            $table->boolean('kill_switch')->default(false);
            $table->string('kill_reason', 500)->nullable();
            $table->unsignedTinyInteger('max_autonomy_level')->default(3);
            $table->json('forbidden_tools')->nullable();
            $table->unsignedSmallInteger('max_tasks_per_run')->default(10);
            $table->unsignedSmallInteger('max_concurrent_runs')->default(20);
            $table->unsignedSmallInteger('task_timeout_seconds')->default(900);
            $table->unsignedSmallInteger('run_timeout_minutes')->default(120);
            $table->unsignedInteger('lock_version')->default(0);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique('scope');
        });

        Schema::create('agent_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('autonomy_level')->default(1); // 0 monitor | 1 recommend | 2 prepare | 3 controlled auto
            $table->boolean('emergency_stop')->default(false);
            $table->json('allowed_actions')->nullable();
            $table->json('forbidden_actions')->nullable();
            $table->unsignedSmallInteger('max_daily_tasks')->default(20);
            $table->unsignedBigInteger('max_monthly_ai_spend_micro_usd')->default(5_000_000);
            $table->unsignedSmallInteger('max_monthly_refreshes')->default(4);
            $table->unsignedSmallInteger('max_monthly_new_articles')->default(0);
            $table->unsignedTinyInteger('publish_window_start')->default(8);
            $table->unsignedTinyInteger('publish_window_end')->default(20);
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->json('approval_requirements')->nullable();
            $table->json('min_evidence')->nullable();
            $table->json('quality_thresholds')->nullable();
            $table->ulid('principal_user_id')->nullable(); // user whose permissions bound the agent
            $table->ulid('level3_enabled_by')->nullable();
            $table->timestamp('level3_enabled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->ulid('updated_by')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique('site_id');
            $table->unique(['workspace_id', 'id']);
        });

        Schema::create('automation_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('kind', 24); // daily_monitoring | weekly_audit | monthly_review | content_refresh | opportunity_discovery | reporting
            $table->string('frequency', 8); // daily | weekly | monthly
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 1 = Monday (ISO)
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..28
            $table->char('local_time', 5)->default('07:00');
            $table->string('timezone', 64);
            $table->string('status', 12)->default('active'); // active | paused | disabled
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->ulid('last_run_id')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->boolean('notify_on_failure')->default(true);
            $table->ulid('created_by')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['site_id', 'kind'], 'auto_sched_site_kind');
            $table->index(['status', 'next_run_at'], 'auto_sched_due');
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('schedule_id')->nullable();
            $table->string('trigger', 12); // schedule | manual
            $table->string('kind', 24);
            $table->string('status', 20)->default('queued'); // queued | running | paused | awaiting_approval | completed | failed | cancelled
            $table->string('phase', 12)->default('observe'); // observe | analyze | plan | estimate | approve | execute | verify | learn | done
            $table->string('active_key', 64)->nullable(); // site id while running → one active run per site
            $table->unsignedTinyInteger('autonomy_level');
            $table->json('policy_snapshot');
            $table->unsignedSmallInteger('max_tasks');
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('actual_cost_micro_usd')->default(0);
            $table->json('observations')->nullable();
            $table->json('plan')->nullable();
            $table->json('summary')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique('active_key');
            $table->unique('idempotency_key');
            $table->index(['site_id', 'created_at'], 'agent_runs_site_created');
            $table->index(['status', 'deadline_at'], 'agent_runs_status_deadline');
        });

        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('agent_run_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('tool', 32);
            $table->string('status', 20)->default('pending'); // pending | awaiting_approval | approved | queued | running | succeeded | failed | rejected | cancelled | skipped | blocked
            $table->string('decision', 20); // allow | require_approval | deny
            $table->json('decision_reasons');
            $table->json('input');
            $table->json('policy_snapshot');
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('actual_cost_micro_usd')->default(0);
            $table->string('credit_key', 120)->nullable();
            $table->json('output')->nullable();
            $table->json('evidence')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('idempotency_key', 160);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'agent_run_id'])->references(['workspace_id', 'id'])->on('agent_runs')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique('idempotency_key');
            $table->index(['agent_run_id', 'sequence'], 'agent_tasks_run_seq');
            $table->index(['site_id', 'status'], 'agent_tasks_site_status');
            $table->index(['status', 'next_attempt_at'], 'agent_tasks_retry');
        });

        Schema::create('agent_action_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id')->nullable();
            $table->ulid('agent_run_id')->nullable();
            $table->ulid('agent_task_id')->nullable();
            $table->string('event', 48);
            $table->string('level', 8)->default('info'); // info | warning | error
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->string('actor_type', 8); // agent | user | system | admin
            $table->ulid('actor_id')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'created_at'], 'agent_logs_ws_created');
            $table->index(['agent_run_id', 'created_at'], 'agent_logs_run_created');
        });

        Schema::create('agent_memories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('kind', 24); // observation | recommendation_outcome | user_feedback | experiment_outcome
            $table->string('content', 1000);
            $table->json('evidence')->nullable();
            $table->string('source_ref', 120)->nullable();
            $table->boolean('verified')->default(false); // only experiment/user-confirmed facts are verified
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->index(['site_id', 'kind', 'created_at'], 'agent_mem_site_kind');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        foreach (['agent_memories', 'agent_action_logs', 'agent_tasks', 'agent_runs', 'automation_schedules', 'agent_configs', 'agent_policies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
