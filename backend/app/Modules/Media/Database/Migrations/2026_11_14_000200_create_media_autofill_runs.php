<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto images after a draft (STYLE-AND-AUTO-IMAGES.md §2): one row per
 * article with the image target, what was filled from the library / by AI,
 * the AI jobs still running and why the target was not reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_autofill_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('article_id');
            $table->ulid('version_id')->nullable();
            $table->unsignedTinyInteger('target')->default(2);
            $table->string('status', 16)->default('running'); // running | completed | incomplete | failed
            $table->unsignedTinyInteger('filled')->default(0);
            $table->unsignedTinyInteger('library_count')->default(0);
            $table->unsignedTinyInteger('ai_count')->default(0);
            $table->json('pending')->nullable(); // [{job_id, placement_id}]
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'article_id'])->references(['workspace_id', 'id'])->on('articles')->cascadeOnDelete();
            $table->unique('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_autofill_runs');
    }
};
