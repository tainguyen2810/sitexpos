<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — SEO analytics (ANALYTICS-ARCHITECTURE.md, GSC-INTEGRATION.md).
 *
 * The Google OAuth link itself is Phase 4's `search_console_connections`
 * (one connection + one property per site) — not duplicated here.
 * `analytics_properties` is the per-site ingestion state (checkpoint,
 * freshness) and the extension point for other providers (GA4).
 * Metric tables are append/upsert-only, deduplicated by natural unique
 * keys (site, date, search type, dimension hash) so re-syncs never double count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->json('thresholds')->nullable();
            $table->json('health_weights')->nullable();
            $table->unsignedSmallInteger('backfill_days')->default(90);
            $table->unsignedSmallInteger('raw_retention_days')->default(490);
            $table->unsignedInteger('lock_version')->default(0);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique('workspace_id');
        });

        Schema::create('analytics_properties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('provider', 16); // gsc | ga4 (adapter not implemented)
            $table->string('property_ref', 300)->nullable();
            $table->string('status', 24)->default('pending'); // pending | active | error | disconnected
            $table->date('first_date')->nullable();
            $table->date('last_complete_date')->nullable(); // sync checkpoint
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 16)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'provider']);
            $table->unique(['workspace_id', 'id']);
        });

        Schema::create('analytics_sync_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('provider', 16)->default('gsc');
            $table->string('kind', 16); // incremental | backfill | manual
            $table->string('status', 16)->default('queued'); // queued | running | completed | partial | failed | skipped
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->date('checkpoint_date')->nullable();
            $table->json('progress')->nullable(); // per day/dimension cursor
            $table->unsignedInteger('rows_upserted')->default(0);
            $table->unsignedInteger('api_requests')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->ulid('job_run_id')->nullable();
            $table->ulid('triggered_by')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique('idempotency_key');
            $table->index(['site_id', 'created_at'], 'an_sync_site_created');
            $table->index(['status', 'next_retry_at'], 'an_sync_status_retry');
        });

        Schema::create('search_performance_daily', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->date('date');
            $table->string('search_type', 8)->default('web'); // web | image | video | news | discover | googleNews
            $table->string('device', 8); // desktop | mobile | tablet
            $table->char('country', 3); // ISO-3166 alpha-3 as returned by GSC
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 8, 2);
            $table->boolean('is_demo')->default(false);
            $table->timestamp('synced_at')->nullable();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'date', 'search_type', 'device', 'country'], 'spd_natural');
            $table->index(['workspace_id', 'date'], 'spd_ws_date');
        });

        foreach (['search_query_metrics' => ['query_hash', 'query', 500], 'search_page_metrics' => ['page_hash', 'page_url', 1000]] as $name => [$hash, $col, $len]) {
            Schema::create($name, function (Blueprint $table) use ($name, $hash, $col, $len) {
                $table->ulid('id')->primary();
                $table->ulid('workspace_id');
                $table->ulid('site_id');
                $table->date('date');
                $table->string('search_type', 8)->default('web');
                $table->char($hash, 40); // sha1 of the normalized value
                $table->string($col, $len);
                if ($name === 'search_page_metrics') {
                    $table->ulid('article_id')->nullable(); // filled by URL mapping (exact URL only)
                }
                $table->unsignedInteger('clicks');
                $table->unsignedInteger('impressions');
                $table->decimal('position', 8, 2);
                $table->boolean('is_demo')->default(false);
                $table->timestamp('synced_at')->nullable();

                $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
                $prefix = $name === 'search_query_metrics' ? 'sqm' : 'spm';
                $table->unique(['site_id', 'date', 'search_type', $hash], $prefix.'_natural');
                $table->index(['site_id', $hash, 'date'], $prefix.'_site_hash_date');
                if ($name === 'search_page_metrics') {
                    $table->index(['site_id', 'article_id', 'date'], 'spm_site_article_date');
                }
            });
        }

        // Window aggregate (query × page) for cannibalization; replaced per window.
        Schema::create('search_query_page_metrics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('search_type', 8)->default('web');
            $table->char('query_hash', 40);
            $table->string('query', 500);
            $table->char('page_hash', 40);
            $table->string('page_url', 1000);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 8, 2);
            $table->boolean('is_demo')->default(false);

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'period_end', 'search_type', 'query_hash', 'page_hash'], 'sqpm_natural');
            $table->index(['site_id', 'period_end', 'query_hash'], 'sqpm_site_period_query');
        });

        Schema::create('analytics_url_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->char('page_hash', 40);
            $table->string('page_url', 1000);
            $table->ulid('article_id')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('source', 16); // publisher | publisher_history | canonical | inventory | manual
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'page_hash'], 'aum_site_page');
            $table->index(['site_id', 'article_id'], 'aum_site_article');
        });

        Schema::create('content_performance_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->char('page_hash', 40);
            $table->string('page_url', 1000);
            $table->ulid('article_id')->nullable();
            $table->date('period_end');
            $table->unsignedSmallInteger('window_days');
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 8, 2)->nullable();
            $table->unsignedInteger('prev_clicks');
            $table->unsignedInteger('prev_impressions');
            $table->decimal('prev_position', 8, 2)->nullable();
            $table->decimal('baseline_clicks', 12, 2)->nullable(); // mean of earlier windows
            $table->unsignedInteger('yoy_clicks')->nullable(); // same window last year (seasonality)
            $table->integer('days_with_data')->default(0);
            $table->string('classification', 32);
            $table->json('labels')->nullable(); // extra flags: high_impr_low_ctr, near_top, decay_candidate, needs_refresh
            $table->json('reasons')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'page_hash', 'period_end', 'window_days'], 'cps_natural');
            $table->index(['site_id', 'period_end', 'classification'], 'cps_site_period_class');
        });

        Schema::create('seo_opportunities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('rule_code', 48);
            $table->string('rule_version', 16);
            $table->char('fingerprint', 40);
            $table->string('target_type', 16); // query | page | article | cluster | site
            $table->string('target_ref', 1000);
            $table->string('target_label', 500)->nullable();
            $table->ulid('article_id')->nullable();
            $table->json('evidence');
            $table->string('detection_rule', 500);
            $table->string('severity', 12); // low | medium | high | critical
            $table->string('impact', 12); // low | medium | high | unknown
            $table->string('impact_basis', 500);
            $table->unsignedTinyInteger('confidence');
            $table->string('confidence_explanation', 500);
            $table->string('recommended_action', 64);
            $table->unsignedBigInteger('estimated_ai_cost_micro_usd')->default(0);
            $table->string('status', 16)->default('open'); // open | accepted | dismissed | resolved | expired
            $table->ulid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('detections')->default(1);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['site_id', 'fingerprint'], 'seo_opp_fingerprint');
            $table->index(['site_id', 'status', 'severity'], 'seo_opp_site_status');
        });

        Schema::create('content_decay_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->char('page_hash', 40);
            $table->string('page_url', 1000);
            $table->ulid('article_id')->nullable();
            $table->string('status', 24); // DECAY_SUSPECTED | NEEDS_INVESTIGATION | CONFIRMED_ISSUE | NO_ACTION
            $table->char('open_key', 40)->nullable(); // page_hash while open → one open event per page
            $table->decimal('decline_pct', 6, 2);
            $table->json('recent');
            $table->json('previous');
            $table->json('baseline')->nullable();
            $table->json('seasonal')->nullable();
            $table->json('query_changes')->nullable();
            $table->json('url_changes')->nullable();
            $table->json('content_changes')->nullable();
            $table->json('signals'); // list of explained signals (correlations, never causes)
            $table->timestamp('detected_at');
            $table->timestamp('last_evaluated_at');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'open_key'], 'cde_open');
            $table->index(['site_id', 'status'], 'cde_site_status');
        });

        Schema::create('seo_recommendations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('opportunity_id')->nullable();
            $table->char('fingerprint', 40);
            $table->string('type', 32);
            $table->string('target_type', 16);
            $table->string('target_ref', 1000);
            $table->string('target_label', 500)->nullable();
            $table->ulid('article_id')->nullable();
            $table->json('evidence');
            $table->text('rationale');
            $table->unsignedTinyInteger('priority');
            $table->string('benefit_hypothesis', 500);
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->string('risk', 12);
            $table->string('risk_notes', 500)->nullable();
            $table->string('required_approval', 16); // editor | publisher | owner
            $table->string('status', 16)->default('proposed'); // proposed | approved | rejected | in_progress | applied | dismissed | failed
            $table->json('suggested_value')->nullable();
            $table->string('source', 12)->default('rule'); // rule | ai | agent
            $table->ulid('agent_run_id')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('seo_opportunities')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['site_id', 'fingerprint'], 'seo_rec_fingerprint');
            $table->index(['site_id', 'status', 'priority'], 'seo_rec_site_status');
        });

        Schema::create('seo_experiments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('article_id')->nullable();
            $table->char('page_hash', 40);
            $table->string('page_url', 1000);
            $table->string('change_type', 24); // seo_title | meta_description | structure | internal_links | content_refresh | new_sections
            $table->string('change_ref', 64)->nullable(); // refresh job / recommendation id
            $table->string('method', 16)->default('before_after'); // never "ab_test": no randomized groups
            $table->timestamp('change_date');
            $table->date('baseline_start');
            $table->date('baseline_end');
            $table->unsignedSmallInteger('observation_days')->default(28);
            $table->date('observation_end');
            $table->json('target_metrics');
            $table->json('baseline')->nullable();
            $table->json('observed')->nullable();
            $table->string('status', 20)->default('observing'); // observing | completed | insufficient_data | cancelled
            $table->string('outcome', 24)->nullable(); // improved | declined | no_clear_change | insufficient_data
            $table->string('outcome_note', 1000)->nullable();
            $table->string('external_factors', 1000)->nullable();
            $table->ulid('created_by')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['site_id', 'status'], 'seo_exp_site_status');
        });

        Schema::create('experiment_observations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('experiment_id');
            $table->date('date');
            $table->string('phase', 12); // baseline | observation
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 8, 2)->nullable();
            $table->boolean('is_demo')->default(false);

            $table->foreign(['workspace_id', 'experiment_id'])->references(['workspace_id', 'id'])->on('seo_experiments')->cascadeOnDelete();
            $table->unique(['experiment_id', 'date'], 'exp_obs_natural');
        });

        Schema::create('content_refresh_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('article_id');
            $table->ulid('recommendation_id')->nullable();
            $table->ulid('agent_task_id')->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('active_key', 64)->nullable(); // article id while not terminal
            $table->json('wp_check')->nullable();
            $table->ulid('base_version_id')->nullable();
            $table->char('base_content_hash', 64)->nullable();
            $table->json('analysis')->nullable();
            $table->json('brief')->nullable();
            $table->json('revision_ids')->nullable();
            $table->ulid('new_version_id')->nullable();
            $table->json('diff')->nullable();
            $table->json('fact_check')->nullable();
            $table->json('seo_audit')->nullable();
            $table->ulid('publishing_job_id')->nullable();
            $table->ulid('experiment_id')->nullable();
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique('active_key');
            $table->unique('idempotency_key');
            $table->index(['site_id', 'status'], 'crj_site_status');
        });

        Schema::create('seo_health_scores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->date('date');
            $table->unsignedTinyInteger('score')->nullable(); // null when no component has data
            $table->json('components');
            $table->json('weights');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'date'], 'shs_site_date');
        });

        Schema::create('seo_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id')->nullable();
            $table->string('period_type', 12); // weekly | monthly | custom
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('queued'); // queued | ready | failed
            $table->json('payload')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->ulid('generated_by')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'period_end'], 'seo_rep_ws_period');
        });

        Schema::create('copilot_queries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('user_id');
            $table->ulid('site_id')->nullable();
            $table->string('question', 1000);
            $table->string('intent', 32);
            $table->json('answer');
            $table->json('data_refs')->nullable();
            $table->string('mode', 12); // ai | rules
            $table->string('model', 120)->nullable();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'user_id', 'created_at'], 'copilot_ws_user');
        });
    }

    public function down(): void
    {
        foreach (['copilot_queries', 'seo_reports', 'seo_health_scores', 'content_refresh_jobs', 'experiment_observations', 'seo_experiments',
            'seo_recommendations', 'content_decay_events', 'seo_opportunities', 'content_performance_snapshots', 'analytics_url_mappings',
            'search_query_page_metrics', 'search_page_metrics', 'search_query_metrics', 'search_performance_daily', 'analytics_sync_runs',
            'analytics_properties', 'analytics_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
