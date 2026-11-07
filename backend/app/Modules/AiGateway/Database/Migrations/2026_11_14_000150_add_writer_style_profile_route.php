<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the `writer.style_profile` AI route on existing installs by copying
 * the models of `writer.brief` (fresh installs get it from the registry
 * sync, which runs after migrations). Data only; a Super Admin can re-route
 * it in AI Settings. Never overwrites an existing route.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('ai_task_routes')->where('task_key', 'writer.style_profile')->exists()) {
            return;
        }
        $base = DB::table('ai_task_routes')->where('task_key', 'writer.brief')->first();
        if ($base === null) {
            return;
        }
        $row = (array) $base;
        $row['id'] = strtolower((string) Str::ulid());
        $row['task_key'] = 'writer.style_profile';
        $row['description'] = 'Writer: learn the writing style of sample articles (style guide only, never copied text)';
        $row['max_output_tokens'] = 3000;
        $row['allow_paid_retry'] = false;
        $row['created_at'] = now();
        $row['updated_at'] = now();
        DB::table('ai_task_routes')->insert($row);
    }

    public function down(): void
    {
        // Keep the route: an admin may have re-routed it.
    }
};
