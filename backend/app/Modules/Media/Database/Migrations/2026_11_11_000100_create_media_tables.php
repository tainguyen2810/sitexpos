<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Media Engine (MEDIA-ENGINE.md). Every row is tenant-scoped;
 * composite (workspace_id, *) foreign keys make cross-tenant references
 * impossible at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which media sources are enabled for a site (and their sync state).
        Schema::create('media_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('type', 24); // wordpress | woocommerce | upload | ai | stock | infographic
            $table->boolean('enabled')->default(true);
            $table->string('status', 16)->default('idle'); // idle | syncing | failed | unavailable
            $table->json('config')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'type']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id')->nullable();
            $table->string('source_type', 24); // wordpress | woocommerce | upload | ai | stock | infographic
            $table->string('source_url', 1000)->nullable();
            $table->string('source_ref', 64)->nullable(); // WordPress attachment / product id
            $table->string('original_filename', 190)->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->char('dhash', 16)->nullable(); // perceptual hash for near-duplicates
            $table->ulid('duplicate_of_id')->nullable();
            $table->string('storage_path', 300)->nullable(); // null = remote reference only (WordPress / WooCommerce)
            $table->string('license', 32); // owned | customer_provided | wordpress_site | ai_generated | licensed_stock | unknown
            $table->string('attribution', 500)->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_provider', 64)->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->text('ai_prompt')->nullable();
            $table->ulid('generation_job_id')->nullable();
            $table->string('title', 200)->nullable();
            $table->string('alt_text', 250)->nullable();
            $table->string('caption', 500)->nullable();
            $table->json('metadata')->nullable(); // infographic spec, product info, wp sizes...
            $table->string('status', 16)->default('processing'); // processing | ready | failed | rejected
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('wp_media_id')->nullable(); // set once uploaded to / imported from WordPress
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['site_id', 'source_type', 'source_ref'], 'media_assets_site_source_ref_unique');
            $table->index(['workspace_id', 'sha256']);
            $table->index(['workspace_id', 'site_id', 'source_type', 'created_at'], 'media_assets_ws_site_type_created_index');
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('media_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('media_asset_id');
            $table->string('kind', 16); // original | optimized | webp | avif | thumbnail | crop
            $table->string('mime', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('storage_path', 300);
            $table->json('params')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'media_asset_id'])->references(['workspace_id', 'id'])->on('media_assets')->cascadeOnDelete();
            $table->unique(['media_asset_id', 'kind']);
        });

        Schema::create('image_generation_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->ulid('article_id')->nullable();
            $table->ulid('parent_job_id')->nullable(); // regeneration chain
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('image_type', 24); // featured | section | concept | infographic_background
            $table->string('section_key', 40)->nullable();
            $table->string('aspect_ratio', 8);
            $table->string('style', 40);
            $table->string('quality', 16);
            $table->unsignedTinyInteger('image_count');
            $table->json('brand_colors')->nullable();
            $table->string('prompt_template', 40)->nullable();
            $table->text('prompt');
            $table->boolean('prompt_edited')->default(false);
            $table->string('status', 16)->default('queued'); // queued | running | succeeded | failed | blocked | canceled
            $table->unsignedBigInteger('budget_cap_micro_usd');
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->string('provider', 64)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('ai_request_id', 64)->nullable();
            $table->string('call_key', 160)->unique();
            $table->json('result_asset_ids')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->ulid('job_run_id')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['article_id', 'created_at']);
        });

        Schema::create('image_placements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('media_asset_id')->nullable();
            $table->string('slot', 16); // featured | intro | section | comparison | infographic | product
            $table->string('section_key', 40)->nullable();
            $table->string('section_heading', 300)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('alt_text', 250)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('status', 16)->default('suggested'); // suggested | accepted | rejected
            $table->string('reason', 300)->nullable();
            $table->string('origin', 16)->default('engine'); // engine | user
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            // MySQL cannot SET NULL through a composite key whose workspace_id is NOT NULL: single-column key (tenant match is enforced by the scoped models).
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['article_id', 'status', 'position']);
        });

        // Super Admin limits (scope global | workspace).
        Schema::create('media_limits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope_key', 40)->unique(); // 'global' | 'ws:<workspace id>'
            $table->unsignedBigInteger('max_storage_bytes')->nullable();
            $table->unsignedInteger('max_uploads_per_day')->nullable();
            $table->unsignedInteger('max_ai_images_per_day')->nullable();
            $table->unsignedBigInteger('max_ai_cost_per_job_micro_usd')->nullable();
            $table->boolean('ai_images_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['media_limits', 'image_placements', 'image_generation_jobs', 'media_versions', 'media_assets', 'media_sources'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
