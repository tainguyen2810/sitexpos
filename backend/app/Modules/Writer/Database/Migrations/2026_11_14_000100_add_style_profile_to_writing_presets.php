<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned writing style (STYLE-AND-AUTO-IMAGES.md). Additive only:
 * - style_profile: the style guide the user reviewed and applied (fed to the writer);
 * - style_draft:   the last AI analysis waiting for review (never used until applied);
 * - style_sources: which samples were analysed (title/url/word count, never the text);
 * - style_learned_at: when the applied profile was last changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writing_presets', function (Blueprint $table) {
            $table->json('style_profile')->nullable();
            $table->json('style_draft')->nullable();
            $table->json('style_sources')->nullable();
            $table->timestamp('style_learned_at')->nullable();
        });

    }

    public function down(): void
    {
        Schema::table('writing_presets', function (Blueprint $table) {
            $table->dropColumn(['style_profile', 'style_draft', 'style_sources', 'style_learned_at']);
        });
    }
};
