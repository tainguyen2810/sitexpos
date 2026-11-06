<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — platform-level (not tenant) operations data: settings edited by the
 * Super Admin, backup runs, monitoring alerts and upgrade runs. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // system_settings already exists since Phase 1 (key, value JSON, updated_by) and was
        // unused. Additive change only: flag secrets (their JSON value is a Crypt string).
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('is_secret')->default(false)->after('value');
        });

        Schema::create('system_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('status', 16)->default('running'); // running | succeeded | failed
            $table->string('trigger', 16)->default('schedule'); // schedule | manual | pre_upgrade
            $table->string('file_name', 190)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('contents')->nullable(); // ["database","storage","config"]
            $table->string('offsite_status', 16)->default('not_configured'); // not_configured | uploaded | failed
            $table->string('offsite_disk', 40)->nullable();
            $table->string('verify_status', 16)->default('not_verified'); // not_verified | verified | failed
            $table->timestamp('verified_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'finished_at']);
        });

        Schema::create('system_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('check_key', 64);
            $table->string('severity', 16); // warning | critical | recovered
            $table->string('summary', 500);
            $table->json('channels')->nullable(); // {"mail":"sent","telegram":"skipped"}
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['check_key', 'created_at']);
        });

        Schema::create('system_upgrade_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('from_version', 32)->nullable();
            $table->string('to_version', 32);
            $table->string('status', 16)->default('queued'); // queued | running | succeeded | failed
            $table->json('migrations')->nullable(); // names applied
            $table->json('steps')->nullable();
            $table->string('error', 500)->nullable();
            $table->ulid('requested_by');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_upgrade_runs');
        Schema::dropIfExists('system_alerts');
        Schema::dropIfExists('system_backups');
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('is_secret');
        });
    }
};
