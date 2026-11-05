<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — AI Content Writer, Evidence Research & Fact-Check (module Writer).
 * See DATABASE.md §3.9.
 *
 * Reuse instead of duplication:
 * - workflow steps / retries / leases live in the Phase 3 engine tables
 *   (workflow_runs, workflow_steps); `article_workflow_runs` only links an
 *   article to its engine runs;
 * - AI calls are logged by the AI Gateway (ai_requests); `article_ai_usage`
 *   attributes each call to tenant / site / campaign / article / step;
 * - Phase 4 already has `research_sources` and `source_snapshots` (SEO
 *   research, headings only, no competitor text). Evidence research needs
 *   different data (full text excerpts + hash), so it uses
 *   `evidence_sources` / `evidence_snapshots` / `evidence_items`.
 * - "article_settings" is the `settings` JSON column of `articles` (one row
 *   per article; reusable configurations are `writing_presets`).
 *
 * Every tenant table carries workspace_id; children reference parents
 * through composite FKs (workspace_id, parent_id) → parent(workspace_id, id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writing_presets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id')->nullable();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->string('tone', 120)->nullable();
            $table->text('writing_style')->nullable();
            $table->json('preferred_terms')->nullable();
            $table->json('forbidden_phrases')->nullable();
            $table->json('facts')->nullable();
            $table->json('target_customers')->nullable();
            $table->string('geographic_context', 300)->nullable();
            $table->text('editorial_guidelines')->nullable();
            $table->string('cta', 500)->nullable();
            $table->json('defaults')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('writing_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('campaign_id')->nullable();
            $table->ulid('writing_preset_id')->nullable();
            $table->string('source', 16)->default('manual');
            $table->string('status', 16)->default('running');
            $table->unsignedSmallInteger('total')->default(0);
            $table->unsignedTinyInteger('concurrency')->default(2);
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('per_article_budget_micro_usd');
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('reserved_cost_micro_usd')->default(0);
            $table->string('idempotency_key', 120)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('campaign_id')->nullable();
            $table->ulid('content_idea_id')->nullable();
            $table->ulid('writing_preset_id')->nullable();
            $table->ulid('writing_batch_id')->nullable();
            $table->string('mode', 16)->default('manual');
            $table->string('title', 300);
            $table->string('status', 24)->default('IDEA_SELECTED');
            $table->string('status_before_failure', 24)->nullable();
            $table->string('primary_keyword', 190);
            $table->json('secondary_keywords')->nullable();
            $table->string('search_intent', 24);
            $table->string('content_type', 24);
            $table->string('language', 10)->default('vi');
            $table->string('country', 8)->default('VN');
            $table->json('settings');
            $table->string('review_mode', 16)->default('step_by_step');
            $table->unsignedBigInteger('budget_limit_micro_usd');
            $table->unsignedBigInteger('spent_micro_usd')->default(0);
            $table->ulid('current_version_id')->nullable();
            $table->ulid('approved_version_id')->nullable();
            $table->unsignedTinyInteger('revision_rounds')->default(0);
            $table->unsignedSmallInteger('unresolved_critical')->default(0);
            $table->unsignedSmallInteger('unresolved_high')->default(0);
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->json('duplicate_check')->nullable();
            $table->string('duplicate_decision', 16)->nullable();
            $table->string('blocked_reason', 64)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->mediumText('autosave_html')->nullable();
            $table->ulid('autosave_base_version_id')->nullable();
            $table->timestamp('autosaved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->string('idempotency_key', 120)->nullable();
            $table->char('fingerprint', 40);
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'writing_batch_id'])->references(['workspace_id', 'id'])->on('writing_batches')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'status', 'updated_at']);
            $table->index(['workspace_id', 'site_id', 'fingerprint']);
            $table->index(['workspace_id', 'content_idea_id']);
            $table->index('writing_batch_id');
        });

        Schema::create('writing_batch_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('writing_batch_id');
            $table->ulid('content_idea_id')->nullable();
            $table->string('title', 300);
            $table->ulid('article_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('status', 24)->default('queued');
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign(['workspace_id', 'writing_batch_id'])->references(['workspace_id', 'id'])->on('writing_batches')->cascadeOnDelete();
            $table->unique(['writing_batch_id', 'content_idea_id']);
            $table->index(['writing_batch_id', 'status', 'position']);
        });

        Schema::create('article_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->unsignedInteger('number');
            $table->string('source', 16);
            $table->string('title', 300);
            $table->mediumText('content_html');
            $table->char('content_hash', 64);
            $table->json('section_hashes')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->ulid('parent_version_id')->nullable();
            $table->string('change_summary', 500)->nullable();
            $table->string('model', 120)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['article_id', 'number']);
        });

        Schema::create('article_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('outline_id');
            $table->string('section_key', 40);
            $table->unsignedSmallInteger('position');
            $table->string('heading', 300);
            $table->unsignedTinyInteger('level')->default(2);
            $table->string('status', 16)->default('pending');
            $table->mediumText('content_html')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->json('citations')->nullable();
            $table->json('notes')->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['outline_id', 'section_key']);
            $table->index(['article_id', 'position']);
        });

        // ---- Evidence research --------------------------------------------
        Schema::create('research_dossiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->string('status', 16)->default('pending');
            $table->string('depth', 16)->default('standard');
            $table->boolean('retrieval_available')->default(true);
            $table->unsignedSmallInteger('questions_count')->default(0);
            $table->unsignedSmallInteger('sources_count')->default(0);
            $table->unsignedSmallInteger('usable_sources_count')->default(0);
            $table->unsignedSmallInteger('evidence_count')->default(0);
            $table->unsignedSmallInteger('rejected_evidence_count')->default(0);
            $table->json('warnings')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('research_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('research_dossier_id');
            $table->unsignedSmallInteger('position');
            $table->string('question', 500);
            $table->string('purpose', 500)->nullable();
            $table->string('origin', 16)->default('ai');
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_dossier_id'])->references(['workspace_id', 'id'])->on('research_dossiers')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['research_dossier_id', 'position']);
        });

        Schema::create('evidence_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('research_dossier_id');
            $table->string('url', 1000);
            $table->char('url_hash', 64);
            $table->string('final_url', 1000)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('publisher', 300)->nullable();
            $table->string('author', 300)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('source_type', 24)->default('other');
            $table->string('discovered_via', 24);
            $table->string('retrieval_status', 24)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedTinyInteger('reliability_score')->nullable();
            $table->json('reliability')->nullable();
            $table->string('license_note', 500)->nullable();
            $table->ulid('snapshot_id')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_dossier_id'])->references(['workspace_id', 'id'])->on('research_dossiers')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['research_dossier_id', 'url_hash']);
            $table->index(['workspace_id', 'url_hash', 'retrieved_at']);
        });

        Schema::create('evidence_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->string('url', 1000);
            $table->char('url_hash', 64);
            $table->string('content_type', 100)->nullable();
            $table->mediumText('content_text');
            $table->char('content_hash', 64);
            $table->unsignedInteger('byte_size')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'url_hash', 'fetched_at']);
        });

        Schema::create('evidence_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('research_dossier_id');
            $table->ulid('evidence_source_id');
            $table->ulid('snapshot_id');
            $table->ulid('research_question_id')->nullable();
            $table->unsignedInteger('ref');
            $table->text('excerpt');
            $table->text('statement');
            $table->string('date_context', 190)->nullable();
            $table->string('version_context', 190)->nullable();
            $table->unsignedTinyInteger('relevance')->default(50);
            $table->string('extracted_by_model', 120)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_dossier_id'])->references(['workspace_id', 'id'])->on('research_dossiers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'evidence_source_id'])->references(['workspace_id', 'id'])->on('evidence_sources')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['research_dossier_id', 'ref']);
        });

        // ---- Brief, outline -------------------------------------------------
        Schema::create('content_briefs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('research_dossier_id')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->json('data');
            $table->json('warnings')->nullable();
            $table->string('generated_by_model', 120)->nullable();
            $table->ulid('edited_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['article_id', 'version']);
        });

        Schema::create('article_outlines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('content_brief_id');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->json('sections');
            $table->json('warnings')->nullable();
            $table->string('generated_by_model', 120)->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['article_id', 'version']);
        });

        // ---- Claims and fact-check -------------------------------------------
        Schema::create('article_claims', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('article_version_id');
            $table->string('section_key', 40)->nullable();
            $table->text('claim_text');
            $table->char('claim_hash', 40);
            $table->string('category', 24);
            $table->string('risk_level', 10);
            $table->string('evidence_requirements', 500)->nullable();
            $table->string('origin', 8)->default('ai');
            $table->string('extracted_by_model', 120)->nullable();
            $table->timestamp('extracted_at');
            $table->string('status', 24)->default('PENDING');
            $table->ulid('latest_result_id')->nullable();
            $table->boolean('is_stale')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['article_version_id', 'claim_hash']);
            $table->index(['article_id', 'article_version_id', 'status']);
        });

        Schema::create('claim_evidence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_claim_id');
            $table->ulid('evidence_item_id');
            $table->string('relation', 12);
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_claim_id'])->references(['workspace_id', 'id'])->on('article_claims')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'evidence_item_id'])->references(['workspace_id', 'id'])->on('evidence_items')->cascadeOnDelete();
            $table->unique(['article_claim_id', 'evidence_item_id', 'relation'], 'claim_evidence_unique');
        });

        Schema::create('fact_check_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('article_version_id');
            $table->ulid('workflow_run_id')->nullable();
            $table->unsignedTinyInteger('round')->default(0);
            $table->string('status', 16)->default('running');
            $table->unsignedSmallInteger('claims_total')->default(0);
            $table->json('counts')->nullable();
            $table->json('consistency_issues')->nullable();
            $table->string('model', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('fact_check_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('fact_check_run_id')->nullable();
            $table->ulid('article_claim_id');
            $table->string('verdict', 24);
            $table->json('supporting')->nullable();
            $table->json('contradicting')->nullable();
            $table->string('date_context', 300)->nullable();
            $table->text('explanation')->nullable();
            $table->json('rule_notes')->nullable();
            $table->string('reviewer_type', 8);
            $table->string('reviewer_model', 120)->nullable();
            $table->ulid('reviewer_user_id')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_claim_id'])->references(['workspace_id', 'id'])->on('article_claims')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['fact_check_run_id', 'verdict']);
        });

        Schema::create('article_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->string('kind', 16);
            $table->string('action', 24);
            $table->unsignedTinyInteger('round')->default(0);
            $table->string('status', 16);
            $table->string('section_key', 40)->nullable();
            $table->ulid('from_version_id')->nullable();
            $table->ulid('to_version_id')->nullable();
            $table->json('claim_ids')->nullable();
            $table->json('request')->nullable();
            $table->json('diff')->nullable();
            $table->mediumText('proposed_html')->nullable();
            $table->string('model', 120)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->ulid('workflow_run_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('quality_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('article_version_id');
            $table->string('rubric_version', 16);
            $table->unsignedTinyInteger('score');
            $table->json('criteria');
            $table->json('issues')->nullable();
            $table->json('duplicates')->nullable();
            $table->json('ai_notes')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('editorial_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('article_version_id');
            $table->string('decision', 20);
            $table->string('note', 2000)->nullable();
            $table->json('checks')->nullable();
            $table->ulid('user_id')->nullable(); // null = system auto-approval
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidated_reason', 300)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('article_workflow_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('workflow_run_id');
            $table->string('kind', 16);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->cascadeOnDelete();
            $table->unique('workflow_run_id');
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('article_ai_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('site_id');
            $table->ulid('campaign_id')->nullable();
            $table->ulid('workflow_run_id')->nullable();
            $table->string('step', 40);
            $table->string('task_key', 64);
            $table->string('provider', 32)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('call_key', 190);
            $table->string('ai_request_id', 26)->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->boolean('usage_reported')->default(false);
            $table->unsignedTinyInteger('retries')->default(0);
            $table->unsignedSmallInteger('fetches')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique('call_key');
            $table->index(['workspace_id', 'created_at']);
            $table->index(['article_id', 'step']);
        });

        Schema::create('writer_usage_counters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->char('period', 7);
            $table->unsignedInteger('articles_reserved')->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'period']);
        });

        // ---- Platform (Super Admin) -------------------------------------------
        Schema::create('writer_settings', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id')->nullable();
            $table->json('values');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'writer_settings', 'writer_usage_counters', 'article_ai_usage', 'article_workflow_runs', 'editorial_approvals',
            'quality_reports', 'article_revisions', 'fact_check_results', 'fact_check_runs', 'claim_evidence', 'article_claims',
            'article_outlines', 'content_briefs', 'evidence_items', 'evidence_snapshots', 'evidence_sources', 'research_questions',
            'research_dossiers', 'article_sections', 'article_versions', 'writing_batch_items', 'articles', 'writing_batches', 'writing_presets',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
