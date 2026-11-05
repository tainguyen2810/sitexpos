<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — WordPress connector (module Sites). See DATABASE.md §3.6.
 *
 * Child tables carry workspace_id and a composite FK (workspace_id, site_id)
 * → sites(workspace_id, id) so a bug in the application layer cannot attach a
 * row of tenant A to a site of tenant B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 255);
            $table->char('url_hash', 64);
            $table->string('status', 32)->default('pending_pairing');
            $table->string('content_language', 10)->default('vi');
            $table->string('wp_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->string('plugin_version', 32)->nullable();
            $table->unsignedBigInteger('publish_author_wp_id')->nullable();
            $table->string('publish_author_name', 190)->nullable();
            $table->string('approval_mode', 16)->default('manual');
            $table->json('settings')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_health_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 16)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'url_hash']);
            $table->index('url_hash');
            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('site_pairings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->index(['site_id', 'used_at']);
        });

        Schema::create('site_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('key_id', 40)->unique();
            $table->text('secret_encrypted');
            $table->string('status', 16)->default('active');
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->index(['site_id', 'status']);
        });

        Schema::create('site_health_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->index(['site_id', 'checked_at']);
        });

        Schema::create('site_taxonomies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('taxonomy', 32);
            $table->unsignedBigInteger('wp_term_id');
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->unsignedBigInteger('parent_wp_id')->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'taxonomy', 'wp_term_id']);
        });

        Schema::create('site_content_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('post_type', 32);
            $table->unsignedBigInteger('wp_post_id');
            $table->string('status', 20);
            $table->string('title', 500);
            $table->string('url', 1000)->nullable();
            $table->string('slug', 200)->nullable();
            $table->text('excerpt')->nullable();
            $table->json('term_ids')->nullable();
            $table->json('product')->nullable();
            $table->boolean('is_managed')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'post_type', 'wp_post_id']);
            $table->index(['workspace_id', 'site_id', 'post_type', 'modified_at'], 'site_content_items_type_modified_index');
        });

        Schema::create('site_publications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('idempotency_key', 100);
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->string('post_type', 32)->default('post');
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wp_status', 20)->nullable();
            $table->string('url', 1000)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'idempotency_key']);
            $table->index(['site_id', 'wp_post_id']);
        });

        Schema::create('site_media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('idempotency_key', 100);
            $table->char('sha256', 64);
            $table->string('mime', 64);
            $table->unsignedInteger('bytes');
            $table->unsignedBigInteger('wp_media_id');
            $table->string('url', 1000);
            $table->timestamps();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'idempotency_key']);
        });

        Schema::create('site_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('workspace_id');
            $table->ulid('site_id');
            $table->string('direction', 8);
            $table->string('operation', 48);
            $table->string('level', 8)->default('info');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'site_id'])->references(['workspace_id', 'id'])->on('sites')->cascadeOnDelete();
            $table->index(['site_id', 'id']);
            $table->index(['site_id', 'level', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_activity_logs');
        Schema::dropIfExists('site_media');
        Schema::dropIfExists('site_publications');
        Schema::dropIfExists('site_content_items');
        Schema::dropIfExists('site_taxonomies');
        Schema::dropIfExists('site_health_checks');
        Schema::dropIfExists('site_credentials');
        Schema::dropIfExists('site_pairings');
        Schema::dropIfExists('sites');
    }
};
