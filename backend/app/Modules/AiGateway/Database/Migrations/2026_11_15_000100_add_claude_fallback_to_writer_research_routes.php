<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.0.6: the writer research routes (questions, source discovery, evidence
 * extraction) had no Claude model, so a platform running only Claude could
 * not search for sources at all. Appends Claude Sonnet as the last fallback
 * on existing installs (fresh installs get it from the registry). Data only,
 * append-only: a route that already uses that model is left as it is, and a
 * Super Admin can re-route in AI Settings.
 */
return new class extends Migration
{
    private const ROUTES = ['writer.research_questions', 'writer.source_discovery', 'writer.source_analysis'];

    public function up(): void
    {
        $claude = DB::table('ai_models')->where('model_key', 'anthropic/claude-sonnet-5-5')->value('id');
        if (! is_string($claude)) {
            return;
        }
        foreach (DB::table('ai_task_routes')->whereIn('task_key', self::ROUTES)->get() as $route) {
            $fallbacks = json_decode((string) ($route->fallback_model_ids ?? '[]'), true);
            $fallbacks = is_array($fallbacks) ? array_values(array_filter($fallbacks, 'is_string')) : [];
            if ($route->primary_model_id === $claude || $route->economy_model_id === $claude || in_array($claude, $fallbacks, true)) {
                continue;
            }
            $fallbacks[] = $claude;
            DB::table('ai_task_routes')->where('id', $route->id)->update(['fallback_model_ids' => json_encode($fallbacks), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Keep: an admin may rely on the fallback.
    }
};
