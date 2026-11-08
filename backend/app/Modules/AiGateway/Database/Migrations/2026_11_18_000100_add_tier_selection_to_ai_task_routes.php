<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.0.13 (ADR-031): a task route names a model level (premium | standard |
 * economy) instead of an explicit model chain, so switching a model on/off
 * on the Providers page flows into every route. Additive only: the old
 * primary/economy/fallback columns stay (no longer read for routing).
 *
 * Existing routes get the level of their current primary model, the kind of
 * that model (text | image) and no pinned model ("automatic").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_task_routes', function (Blueprint $table) {
            $table->string('tier', 16)->default('standard')->after('priority');
            $table->string('kind', 16)->default('text')->after('tier');
            $table->boolean('requires_web_search')->default(false)->after('kind');
            $table->foreignUlid('pinned_model_id')->nullable()->after('requires_web_search')->constrained('ai_models')->nullOnDelete();
        });

        $models = DB::table('ai_models')->get(['id', 'tier', 'kind'])->keyBy('id');
        foreach (DB::table('ai_task_routes')->get(['id', 'task_key', 'primary_model_id']) as $route) {
            $primary = $models->get($route->primary_model_id);
            $tier = $primary !== null && in_array($primary->tier, ['premium', 'standard', 'economy'], true) ? $primary->tier : 'standard';
            DB::table('ai_task_routes')->where('id', $route->id)->update([
                'tier' => $tier,
                'kind' => $primary !== null && $primary->kind === 'image' ? 'image' : 'text',
                'requires_web_search' => $route->task_key === 'writer.source_discovery',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_task_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_model_id');
            $table->dropColumn(['tier', 'kind', 'requires_web_search']);
        });
    }
};
