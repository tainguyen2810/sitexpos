<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — AI Topic Discovery & SEO Planner (module Research).
 * See DATABASE.md §3.8.
 *
 * Every tenant table carries workspace_id. Children reference their parent
 * through a composite FK (workspace_id, parent_id) → parent(workspace_id, id)
 * so an application bug cannot attach tenant A rows to tenant B parents.
 * Platform tables (research_source_settings, research_limits) hold Super
 * Admin configuration only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Website intelligence --------------------------------------------
        Schema::create('website_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('title', 255)->nullable();
            $table->string('tagline', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('region', 10)->nullable();
            $table->string('sitemap_url', 1000)->nullable();
            $table->unsignedInteger('sitemap_url_count')->default(0);
            $table->json('stats')->nullable();
            $table->json('covered_topics')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique('site_id');
        });

        Schema::create('website_knowledge', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('website_profile_id');
            $table->string('kind', 32);
            $table->text('value');
            $table->string('source', 16);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 16)->default('suggested');
            $table->char('fingerprint', 40);
            $table->ulid('edited_by')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'website_profile_id'])->references(['workspace_id', 'id'])->on('website_profiles')->cascadeOnDelete();
            $table->unique(['website_profile_id', 'fingerprint']);
            $table->index(['workspace_id', 'website_profile_id', 'kind']);
        });

        // ---- Campaigns ---------------------------------------------------------
        Schema::create('seo_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('name', 150);
            $table->string('industry', 190);
            $table->string('market', 8);
            $table->string('language', 10);
            $table->json('products')->nullable();
            $table->json('services')->nullable();
            $table->json('audiences')->nullable();
            $table->json('goals')->nullable();
            $table->json('brand_terms')->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'site_id', 'name']);
            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('campaign_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->unsignedSmallInteger('ideas_per_run')->default(20);
            $table->json('scope_keywords')->nullable();
            $table->json('excluded_topics')->nullable();
            $table->unsignedSmallInteger('max_site_pages')->default(30);
            $table->unsignedSmallInteger('max_competitor_pages')->default(15);
            $table->boolean('use_ai_expansion')->default(true);
            $table->boolean('auto_discover')->default(false);
            $table->boolean('auto_calendar')->default(false);
            // Phase 5 switches — stored now, never acted on in Phase 4.
            $table->boolean('auto_create_content')->default(false);
            $table->boolean('auto_publish')->default(false);
            $table->unsignedTinyInteger('posts_per_week')->default(3);
            $table->json('publish_weekdays')->nullable();
            $table->unsignedBigInteger('max_ai_cost_per_run_micro_usd')->default(500000);
            $table->boolean('notify_opportunities')->default(true);
            $table->unsignedTinyInteger('notify_min_score')->default(70);
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->unique('campaign_id');
        });

        Schema::create('research_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->string('frequency', 16)->default('weekly');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('hour_utc')->default(2);
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->ulid('last_research_run_id')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->unique('campaign_id');
            $table->index(['is_enabled', 'next_run_at']);
        });

        // ---- Research runs and sources ------------------------------------------
        Schema::create('research_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('workflow_run_id')->nullable();
            $table->string('trigger', 16)->default('manual');
            $table->string('status', 24)->default('queued');
            $table->string('stage', 48)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('idempotency_key', 120)->nullable();
            $table->json('stats')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->boolean('quota_reserved')->default(false);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index('workflow_run_id');
            $table->index(['workspace_id', 'campaign_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('research_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('research_run_id');
            $table->string('type', 32);
            $table->string('label', 190);
            $table->string('url', 1000)->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('robots_allowed')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_run_id'])->references(['workspace_id', 'id'])->on('research_runs')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['research_run_id', 'type', 'label'], 'research_sources_run_type_label_unique');
            $table->index(['workspace_id', 'campaign_id', 'type']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('research_source_id');
            $table->string('url', 1000);
            $table->char('url_hash', 64);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('title', 500)->nullable();
            $table->string('meta_description', 1000)->nullable();
            $table->string('language', 10)->nullable();
            $table->json('headings')->nullable();
            $table->json('questions')->nullable();
            $table->json('internal_links')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_source_id'])->references(['workspace_id', 'id'])->on('research_sources')->cascadeOnDelete();
            $table->unique(['research_source_id', 'url_hash']);
        });

        // ---- Keywords -----------------------------------------------------------
        Schema::create('topic_clusters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->string('name', 190);
            $table->char('fingerprint', 40);
            $table->string('intent', 24);
            $table->ulid('pillar_keyword_id')->nullable();
            $table->ulid('pillar_content_item_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('opportunity_score')->nullable();
            $table->unsignedInteger('keywords_count')->default(0);
            $table->json('coverage')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->foreign('pillar_content_item_id')->references('id')->on('site_content_items')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['campaign_id', 'fingerprint']);
            $table->index('pillar_keyword_id');
        });

        Schema::create('keywords', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('cluster_id')->nullable();
            $table->string('keyword', 190);
            $table->string('normalized', 190);
            $table->char('fingerprint', 40);
            $table->string('language', 10);
            $table->string('country', 8);
            $table->string('intent', 24);
            $table->string('intent_source', 16)->default('rule');
            $table->string('topic_category', 120)->nullable();
            $table->json('sources');
            $table->string('primary_source', 32);
            $table->json('evidence')->nullable();
            $table->string('status', 16)->default('suggested');
            $table->unsignedTinyInteger('opportunity_score')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('covered_by')->nullable();
            $table->ulid('last_seen_run_id')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('data_collected_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->foreign('cluster_id')->references('id')->on('topic_clusters')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['campaign_id', 'fingerprint']);
            $table->index(['workspace_id', 'campaign_id', 'status', 'opportunity_score'], 'keywords_campaign_status_score_index');
            $table->index(['campaign_id', 'intent']);
        });

        Schema::create('keyword_metrics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('keyword_id');
            $table->string('source', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->unsignedBigInteger('cpc_micro_usd')->nullable();
            $table->unsignedInteger('impressions')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('position', 6, 2)->nullable();
            $table->json('trend')->nullable();
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'keyword_id'])->references(['workspace_id', 'id'])->on('keywords')->cascadeOnDelete();
            $table->unique(['keyword_id', 'source', 'period_start']);
        });

        // ---- Ideas, gaps, links ---------------------------------------------------
        Schema::create('content_ideas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('research_run_id')->nullable();
            $table->ulid('cluster_id')->nullable();
            $table->ulid('primary_keyword_id')->nullable();
            $table->string('title', 300);
            $table->string('search_intent', 24);
            $table->string('content_type', 24);
            $table->string('audience', 300)->nullable();
            $table->text('angle')->nullable();
            $table->string('priority', 8)->default('medium');
            $table->unsignedTinyInteger('opportunity_score')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->text('rationale')->nullable();
            $table->json('evidence')->nullable();
            $table->string('source', 16)->default('ai');
            $table->boolean('is_pillar')->default(false);
            $table->string('status', 16)->default('suggested');
            $table->char('fingerprint', 40);
            $table->ulid('overlap_content_item_id')->nullable();
            $table->unsignedTinyInteger('overlap_score')->nullable();
            $table->json('brief')->nullable();
            $table->timestamp('briefed_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->foreign('cluster_id')->references('id')->on('topic_clusters')->nullOnDelete();
            $table->foreign('primary_keyword_id')->references('id')->on('keywords')->nullOnDelete();
            $table->foreign('overlap_content_item_id')->references('id')->on('site_content_items')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['campaign_id', 'fingerprint']);
            $table->index(['workspace_id', 'campaign_id', 'status', 'opportunity_score'], 'content_ideas_campaign_status_score_index');
        });

        Schema::create('content_idea_keywords', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('content_idea_id');
            $table->ulid('keyword_id');
            $table->string('role', 16);
            $table->timestamps();

            $table->foreign(['workspace_id', 'content_idea_id'])->references(['workspace_id', 'id'])->on('content_ideas')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'keyword_id'])->references(['workspace_id', 'id'])->on('keywords')->cascadeOnDelete();
            $table->unique(['content_idea_id', 'keyword_id']);
            $table->index('keyword_id');
        });

        Schema::create('competitor_websites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->string('name', 150);
            $table->string('url', 255);
            $table->char('url_hash', 64);
            $table->string('status', 16)->default('active');
            $table->boolean('robots_allowed')->nullable();
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['campaign_id', 'url_hash']);
        });

        Schema::create('competitor_findings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('competitor_website_id');
            $table->ulid('research_run_id')->nullable();
            $table->string('kind', 16);
            $table->string('title', 500);
            $table->string('url', 1000)->nullable();
            $table->json('headings')->nullable();
            $table->char('fingerprint', 40);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'competitor_website_id'])->references(['workspace_id', 'id'])->on('competitor_websites')->cascadeOnDelete();
            $table->unique(['competitor_website_id', 'fingerprint']);
        });

        Schema::create('content_gaps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('research_run_id')->nullable();
            $table->ulid('cluster_id')->nullable();
            $table->ulid('competitor_website_id')->nullable();
            $table->string('gap_type', 32);
            $table->string('topic', 300);
            $table->text('description')->nullable();
            $table->json('evidence')->nullable();
            $table->json('related_content_item_ids')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('status', 16)->default('open');
            $table->char('fingerprint', 40);
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->foreign('cluster_id')->references('id')->on('topic_clusters')->nullOnDelete();
            $table->foreign('competitor_website_id')->references('id')->on('competitor_websites')->nullOnDelete();
            $table->unique(['campaign_id', 'fingerprint']);
            $table->index(['workspace_id', 'campaign_id', 'gap_type', 'status']);
        });

        Schema::create('internal_link_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('cluster_id')->nullable();
            $table->string('from_type', 16);
            $table->ulid('from_id');
            $table->string('to_type', 16);
            $table->ulid('to_id');
            $table->string('anchor_text', 190);
            $table->string('reason', 300);
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('status', 16)->default('suggested');
            $table->char('fingerprint', 40);
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->foreign('cluster_id')->references('id')->on('topic_clusters')->cascadeOnDelete();
            $table->unique(['campaign_id', 'fingerprint']);
        });

        Schema::create('content_calendar_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id');
            $table->ulid('content_idea_id');
            $table->ulid('cluster_id')->nullable();
            $table->date('scheduled_for');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 16)->default('proposed');
            $table->string('source', 16)->default('user');
            $table->string('notes', 500)->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'campaign_id'])->references(['workspace_id', 'id'])->on('seo_campaigns')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'content_idea_id'])->references(['workspace_id', 'id'])->on('content_ideas')->cascadeOnDelete();
            $table->foreign('cluster_id')->references('id')->on('topic_clusters')->nullOnDelete();
            $table->unique('content_idea_id');
            $table->index(['workspace_id', 'campaign_id', 'scheduled_for']);
        });

        // ---- Integrations, notifications, quotas ----------------------------------
        Schema::create('search_console_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('property_url', 300)->nullable();
            $table->string('google_email', 190)->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->ulid('connected_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique('site_id');
        });

        Schema::create('research_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('campaign_id')->nullable();
            $table->string('type', 32);
            $table->string('title', 255);
            $table->string('body', 1000)->nullable();
            $table->json('data')->nullable();
            $table->char('dedupe_key', 40);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('seo_campaigns')->cascadeOnDelete();
            $table->unique(['workspace_id', 'dedupe_key']);
            $table->index(['workspace_id', 'read_at', 'created_at']);
        });

        Schema::create('research_usage_counters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->char('period', 7);
            $table->string('metric', 32);
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'period', 'metric']);
        });

        Schema::create('research_limits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope_key', 40)->unique();
            $table->ulid('workspace_id')->nullable();
            $table->unsignedSmallInteger('max_campaigns');
            $table->unsignedSmallInteger('max_runs_per_month');
            $table->unsignedSmallInteger('max_ideas_per_run');
            $table->unsignedSmallInteger('max_competitors_per_campaign');
            $table->unsignedSmallInteger('max_calendar_items_per_month');
            $table->unsignedTinyInteger('max_concurrent_runs');
            $table->unsignedBigInteger('max_ai_cost_per_run_micro_usd');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('research_source_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('source', 32)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable();
            $table->text('secret_encrypted')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(30);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'research_source_settings', 'research_limits', 'research_usage_counters', 'research_notifications',
            'search_console_connections', 'content_calendar_items', 'internal_link_suggestions', 'content_gaps',
            'competitor_findings', 'competitor_websites', 'content_idea_keywords', 'content_ideas', 'keyword_metrics',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (['keywords', 'topic_clusters', 'source_snapshots', 'research_sources', 'research_runs', 'research_schedules', 'campaign_settings', 'seo_campaigns', 'website_knowledge', 'website_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
