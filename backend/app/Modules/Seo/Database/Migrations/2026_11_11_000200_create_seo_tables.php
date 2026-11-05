<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — SEO metadata, explainable audit, internal links, schema
 * (SEO-AUDIT-RULES.md). `seo_link_suggestions` is article-level and
 * distinct from Phase 4's planner-level `internal_link_suggestions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_metadata', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('site_id');
            $table->string('seo_title', 200)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('slug', 190)->nullable();
            $table->string('canonical_url', 1000)->nullable();
            $table->string('focus_keyword', 190)->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->string('og_title', 200)->nullable();
            $table->string('og_description', 320)->nullable();
            $table->ulid('social_image_asset_id')->nullable();
            $table->boolean('robots_noindex')->default(false);
            $table->boolean('robots_nofollow')->default(false);
            $table->json('schema_types')->nullable(); // chosen schema types for this article
            $table->json('manual_fields')->nullable(); // fields a human edited (never overwritten by regenerate)
            $table->string('source', 16)->default('generated'); // generated | manual
            $table->unsignedInteger('lock_version')->default(0);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique('article_id');
            $table->unique(['workspace_id', 'id']);
        });

        Schema::create('seo_audit_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('site_id');
            $table->ulid('article_version_id')->nullable();
            $table->char('content_hash', 64); // hash of the audited render (content + metadata + media + links)
            $table->string('rules_version', 16);
            $table->string('status', 16)->default('completed'); // running | completed | failed
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedSmallInteger('pass_count')->default(0);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->unsignedSmallInteger('error_count')->default(0);
            $table->unsignedSmallInteger('blocked_count')->default(0);
            $table->boolean('links_checked')->default(false);
            $table->json('summary')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('seo_audit_issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('seo_audit_run_id');
            $table->string('rule', 40);
            $table->string('category', 24);
            $table->string('severity', 8); // PASS | WARNING | ERROR | BLOCKED
            $table->unsignedTinyInteger('weight');
            $table->decimal('points', 5, 2);
            $table->string('message', 500);
            $table->string('explanation', 1000);
            $table->string('fix', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'seo_audit_run_id'])->references(['workspace_id', 'id'])->on('seo_audit_runs')->cascadeOnDelete();
            $table->index(['seo_audit_run_id', 'severity']);
        });

        Schema::create('seo_link_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('site_id');
            $table->unsignedBigInteger('target_wp_post_id');
            $table->string('target_post_type', 32);
            $table->string('target_url', 1000);
            $table->string('target_title', 500);
            $table->string('anchor_text', 190);
            $table->string('section_key', 40)->nullable();
            $table->unsignedSmallInteger('paragraph_index');
            $table->unsignedTinyInteger('relevance');
            $table->json('signals')->nullable(); // semantic overlap, same cluster, intent, anchor match
            $table->string('reason', 500);
            $table->string('status', 16)->default('suggested'); // suggested | accepted | rejected | stale
            $table->timestamp('verified_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['article_id', 'target_wp_post_id']);
            $table->index(['article_id', 'status']);
        });

        Schema::create('schema_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->boolean('injection_enabled')->default(false);
            $table->json('enabled_types'); // Article | BlogPosting | FAQPage | HowTo | BreadcrumbList
            $table->string('organization_name', 190)->nullable();
            $table->string('logo_url', 1000)->nullable();
            $table->boolean('breadcrumbs_supported')->default(false);
            // SEO safety policy: changing canonical to another host or setting noindex
            // requires the site owner to allow it explicitly.
            $table->boolean('allow_canonical_override')->default(false);
            $table->boolean('allow_robots_override')->default(false);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        foreach (['schema_configs', 'seo_link_suggestions', 'seo_audit_issues', 'seo_audit_runs', 'seo_metadata'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
