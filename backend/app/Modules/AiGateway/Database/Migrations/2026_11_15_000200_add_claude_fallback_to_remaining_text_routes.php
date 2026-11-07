<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.0.7: every text route can run on Claude. These routes had no Claude
 * model, so a platform with only an Anthropic key failed them with
 * provider_not_configured (claim extraction, SEO/final review notes, keyword
 * expansion, site profile, analytics recommendations, agent plan, copilot).
 * Appends Claude Sonnet as the last fallback on existing installs (fresh
 * installs get it from the registry). Append-only; a route that already
 * uses the model is left as it is; a Super Admin can re-route in AI Settings.
 */
return new class extends Migration
{
    private const ROUTES = ['keywords.expand', 'research.profile', 'writer.claims', 'writer.seo_review', 'writer.final_review',
        'analytics.recommend', 'agent.plan', 'copilot.answer'];

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
