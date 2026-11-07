<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.0.9: the post author (WordPress user id + public display name) so the
 * AI Writer can learn a writing style "from one author". Additive only;
 * filled by the next sync from plugin ≥ 1.2.0 (older plugins leave NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_content_items', function (Blueprint $table) {
            $table->unsignedBigInteger('author_wp_id')->nullable();
            $table->string('author_name', 200)->nullable();
            $table->index(['site_id', 'author_wp_id'], 'site_content_items_author_index');
        });
    }

    public function down(): void
    {
        Schema::table('site_content_items', function (Blueprint $table) {
            $table->dropIndex('site_content_items_author_index');
            $table->dropColumn(['author_wp_id', 'author_name']);
        });
    }
};
