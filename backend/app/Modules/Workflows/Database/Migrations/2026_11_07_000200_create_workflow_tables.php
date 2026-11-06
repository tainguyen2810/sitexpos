<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — durable workflows: the state machine lives in the database so a
 * worker restart never loses a run (ARCHITECTURE.md §10.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('queue', 32);
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 120)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('current_step', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);   // executions in the current cycle
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->unsignedSmallInteger('cycle')->default(1);      // +1 on each manual retry
            $table->unsignedInteger('version')->default(0);         // optimistic lock for transitions
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('locked_by', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->ulid('job_run_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key'], 'workflow_runs_ws_idem_unique');
            $table->index(['workspace_id', 'status', 'created_at'], 'workflow_runs_ws_status_idx');
            $table->index(['status', 'locked_until'], 'workflow_runs_status_lock_idx');
            $table->index(['status', 'next_attempt_at'], 'workflow_runs_status_next_idx');
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('key', 64);
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('output')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_run_id', 'key']);
        });

        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workflow_run_id', 'id']);
        });

        Schema::create('dead_letters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id')->nullable()->index();
            $table->string('source', 16);                          // workflow | job
            $table->ulid('workflow_run_id')->nullable()->index();
            $table->string('job_class', 191)->nullable();
            $table->string('queue', 32)->nullable();
            $table->string('failed_job_uuid', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status', 16)->default('open');         // open | retried | discarded
            $table->ulid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letters');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_runs');
    }
};
