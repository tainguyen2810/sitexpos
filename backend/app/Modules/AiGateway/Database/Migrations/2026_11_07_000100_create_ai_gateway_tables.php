<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — AI Gateway (DATABASE.md §3.14). Money is integer micro-USD
 * (1 USD = 1,000,000). Prices are per million tokens and versioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('timeout_seconds')->default(60);
            $table->unsignedTinyInteger('max_retries')->default(1);
            $table->unsignedSmallInteger('circuit_failure_threshold')->default(5);
            $table->unsignedSmallInteger('circuit_cooldown_seconds')->default(60);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model_key', 120)->unique();      // registry key: "<provider>/<model_name>"
            $table->string('model_name', 100);               // id sent to the provider API
            $table->string('display_name', 120);
            $table->string('kind', 16)->default('text');     // text | image
            $table->string('tier', 16)->default('standard'); // economy | standard | premium
            $table->json('capabilities');
            $table->boolean('is_enabled')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'model_name']);
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('effective_from');
            $table->char('currency', 3)->default('USD');
            // micro-USD per 1M tokens
            $table->unsignedBigInteger('input_per_mtok')->default(0);
            $table->unsignedBigInteger('cached_input_per_mtok')->default(0);
            $table->unsignedBigInteger('cache_write_per_mtok')->default(0);
            $table->unsignedBigInteger('output_per_mtok')->default(0);
            // optional long-context tier (prompt > threshold tokens)
            $table->unsignedInteger('long_context_threshold')->nullable();
            $table->unsignedBigInteger('long_input_per_mtok')->nullable();
            $table->unsignedBigInteger('long_cached_input_per_mtok')->nullable();
            $table->unsignedBigInteger('long_cache_write_per_mtok')->nullable();
            $table->unsignedBigInteger('long_output_per_mtok')->nullable();
            // micro-USD per unit
            $table->unsignedBigInteger('per_image')->default(0);
            $table->unsignedBigInteger('per_1k_searches')->default(0);
            $table->string('source_url', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['model_id', 'version']);
            $table->index(['model_id', 'effective_from']);
        });

        Schema::create('ai_task_routes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('task_key', 64)->unique();
            $table->string('description', 255)->nullable();
            $table->string('queue', 32)->default('research');
            $table->string('priority', 16)->default('batch');   // interactive | batch
            $table->foreignUlid('primary_model_id')->constrained('ai_models')->restrictOnDelete();
            $table->foreignUlid('economy_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('fallback_model_ids')->nullable();       // ordered list of ai_models.id
            $table->unsignedInteger('max_input_tokens')->default(30000);
            $table->unsignedInteger('max_output_tokens')->default(2000);
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(3);
            // Retry an attempt whose billing outcome is unknown (timeout after send, worker crash)?
            $table->boolean('allow_paid_retry')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignUlid('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->string('task_key', 64);
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->ulid('model_id')->nullable();
            $table->ulid('price_id')->nullable();
            $table->ulid('workflow_run_id')->nullable();
            $table->string('call_key', 160)->nullable();          // idempotency key of the logical call
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->boolean('is_fallback')->default(false);
            $table->string('status', 24);                         // succeeded | failed | skipped
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();     // redacted, never contains secrets
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('billing_uncertain')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedSmallInteger('image_count')->default(0);
            $table->unsignedSmallInteger('search_count')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->char('prompt_hash', 64)->nullable();
            $table->string('provider_request_id', 120)->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['call_key']);
        });

        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 16);                           // global | workspace
            $table->foreignUlid('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->string('scope_key', 40);                       // "global" | "ws:<ulid>"
            $table->string('period', 8);                           // day | month
            $table->unsignedBigInteger('limit_micro_usd');
            $table->unsignedTinyInteger('alert_threshold_pct')->default(80);
            $table->string('action_on_exceed', 16)->default('pause_all'); // alert | pause_batch | pause_all
            $table->boolean('is_enabled')->default(true);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['scope_key', 'period']);
        });

        Schema::create('ai_budget_counters', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 40);
            $table->string('period', 8);
            $table->date('period_start');
            $table->unsignedBigInteger('reserved_micro_usd')->default(0);
            $table->unsignedBigInteger('spent_micro_usd')->default(0);
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['scope_key', 'period', 'period_start'], 'ai_budget_counters_scope_unique');
        });

        Schema::create('ai_budget_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('idempotency_key', 191)->unique();
            $table->ulid('workspace_id')->nullable();
            $table->string('status', 16);                          // reserved | committed | released | expired
            $table->unsignedBigInteger('amount_micro_usd');
            $table->unsignedBigInteger('actual_micro_usd')->nullable();
            $table->json('counter_ids');
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_budget_reservations');
        Schema::dropIfExists('ai_budget_counters');
        Schema::dropIfExists('ai_budgets');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_task_routes');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
