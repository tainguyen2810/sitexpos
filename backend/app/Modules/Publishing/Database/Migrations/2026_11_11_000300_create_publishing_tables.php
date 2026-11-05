<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — idempotent WordPress publisher, scheduler, conflicts, recovery
 * (WORDPRESS-PUBLISHER.md, PUBLISHING-RECOVERY.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('default_mode', 16)->default('manual'); // manual | wp_draft
            $table->boolean('auto_publish_enabled')->default(false);
            $table->ulid('auto_publish_enabled_by')->nullable();
            $table->timestamp('auto_publish_enabled_at')->nullable();
            $table->boolean('require_seo_pass')->default(true); // no ERROR issues before publishing (BLOCKED always blocks)
            $table->boolean('require_media_ready')->default(true);
            $table->boolean('allow_update_published')->default(true);
            $table->unsignedSmallInteger('max_posts_per_day')->default(5);
            $table->unsignedSmallInteger('max_posts_per_campaign_per_day')->default(3);
            $table->unsignedSmallInteger('min_minutes_between_posts')->default(60);
            $table->unsignedTinyInteger('window_start_hour')->default(7);
            $table->unsignedTinyInteger('window_end_hour')->default(22);
            $table->json('window_days')->nullable(); // ISO weekdays 1..7; null = every day
            $table->unsignedSmallInteger('max_schedule_days_ahead')->default(90);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('missed_grace_minutes')->default(180);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique('site_id');
        });

        Schema::create('publishing_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('mode', 16); // wp_draft | publish_now | scheduled
            $table->string('status', 16)->default('running'); // running | paused | completed | canceled
            $table->timestamp('start_at')->nullable();
            $table->unsignedSmallInteger('interval_minutes')->nullable();
            $table->unsignedSmallInteger('total')->default(0);
            $table->string('idempotency_key', 120)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'idempotency_key']);
        });

        // Stable mapping article ⇄ WordPress post, one per (site, external content id).
        Schema::create('wordpress_post_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('article_id');
            $table->string('external_content_id', 64); // "sxa-<article>" or "sxa-<article>-r<n>" for a "new draft" fork
            $table->string('idempotency_key', 100); // sent to the plugin; identifies the logical document
            $table->boolean('is_current')->default(true);
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wp_status', 20)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('slug', 200)->nullable();
            $table->string('site_host', 255);
            $table->ulid('last_published_version_id')->nullable();
            $table->char('last_payload_hash', 64)->nullable();
            $table->char('last_synced_hash', 64)->nullable(); // post_content hash WordPress reported after our write
            $table->char('last_seo_hash', 64)->nullable();
            $table->string('last_modified_gmt', 32)->nullable();
            $table->unsignedBigInteger('last_revision_id')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['site_id', 'external_content_id']);
            $table->unique(['site_id', 'idempotency_key']);
            $table->index(['article_id', 'is_current']);
            $table->index(['site_id', 'wp_post_id']);
        });

        Schema::create('publishing_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('article_id');
            $table->ulid('campaign_id')->nullable();
            $table->ulid('publishing_batch_id')->nullable();
            $table->ulid('mapping_id')->nullable();
            $table->string('mode', 16); // wp_draft | publish_now | scheduled | auto | update
            $table->string('status', 20)->default('DRAFT');
            $table->string('active_key', 80)->nullable()->unique(); // "<site>:<article>" while non-terminal → one active job per article/site
            $table->ulid('article_version_id')->nullable(); // approved version frozen at approval
            $table->char('content_hash', 64)->nullable(); // render hash frozen at approval
            $table->mediumText('rendered_html')->nullable(); // exact HTML sent (history)
            $table->json('payload_meta')->nullable(); // title, slug, seo, schema, images (no bytes)
            $table->json('gate_results')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('wp_target_status', 16)->default('draft'); // draft | publish
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->string('blocked_reason', 64)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('approval_source', 16)->nullable(); // user | system_auto
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            // MySQL cannot SET NULL through a composite key whose workspace_id is NOT NULL: single-column key (tenant match is enforced by the scoped models).
            $table->foreign('publishing_batch_id')->references('id')->on('publishing_batches')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'status', 'updated_at']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['site_id', 'status', 'scheduled_at']);
            $table->index('publishing_batch_id');
        });

        Schema::create('publishing_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('publishing_job_id');
            $table->unsignedTinyInteger('attempt_no');
            $table->string('operation', 16); // create | update | reconcile | state_check
            $table->string('outcome', 16); // succeeded | failed | uncertain | reconciled | conflict | skipped
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('retryable')->default(false);
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'publishing_job_id'])->references(['workspace_id', 'id'])->on('publishing_jobs')->cascadeOnDelete();
            $table->unique(['publishing_job_id', 'attempt_no']);
        });

        Schema::create('wordpress_sync_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('mapping_id');
            $table->string('state', 16); // in_sync | manual_edit | slug_changed | seo_modified | domain_changed | missing | unreachable
            $table->string('wp_status', 20)->nullable();
            $table->string('wp_slug', 200)->nullable();
            $table->char('wp_content_hash', 64)->nullable();
            $table->char('wp_seo_hash', 64)->nullable();
            $table->string('wp_modified_gmt', 32)->nullable();
            $table->unsignedBigInteger('wp_revision_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'mapping_id'])->references(['workspace_id', 'id'])->on('wordpress_post_mappings')->cascadeOnDelete();
            $table->index(['mapping_id', 'checked_at']);
        });

        Schema::create('publishing_conflicts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('article_id');
            $table->ulid('mapping_id');
            $table->ulid('publishing_job_id')->nullable();
            $table->json('types'); // manual_edit | post_missing | slug_changed | domain_changed | revision_changed | seo_modified
            $table->string('status', 16)->default('open'); // open | resolved
            $table->string('resolution', 20)->nullable(); // keep_wordpress | overwrite | merge_manually | new_draft | cancel_update
            $table->json('wp_state')->nullable();
            $table->json('expected_state')->nullable();
            $table->string('note', 1000)->nullable();
            $table->ulid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'mapping_id'])->references(['workspace_id', 'id'])->on('wordpress_post_mappings')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['mapping_id', 'status']);
        });

        Schema::create('publishing_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('publishing_job_id');
            $table->timestamp('scheduled_at'); // UTC
            $table->string('timezone', 64);
            $table->string('local_time', 32); // as shown to the customer, e.g. 2026-11-20 09:00
            $table->string('status', 16)->default('pending'); // pending | dispatched | done | missed | canceled
            $table->timestamp('dispatched_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'publishing_job_id'])->references(['workspace_id', 'id'])->on('publishing_jobs')->cascadeOnDelete();
            $table->unique('publishing_job_id');
            $table->index(['status', 'scheduled_at']);
            $table->index(['site_id', 'scheduled_at']);
        });

        Schema::create('publishing_limits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope_key', 40)->unique(); // 'global' | 'ws:<workspace id>'
            $table->unsignedInteger('max_publishes_per_day')->nullable();
            $table->unsignedSmallInteger('max_bulk_items')->nullable();
            $table->unsignedSmallInteger('max_schedule_days_ahead')->nullable();
            $table->boolean('auto_publish_allowed')->default(true);
            $table->boolean('publishing_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['publishing_limits', 'publishing_schedules', 'publishing_conflicts', 'wordpress_sync_states', 'publishing_attempts',
            'publishing_jobs', 'wordpress_post_mappings', 'publishing_batches', 'publishing_policies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
