<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 supplement (3E) — DeepSeek, custom OpenAI-compatible providers,
 * encrypted API keys, off-peak price windows. Adds columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            // Adapter used for this provider row (openai, gemini, anthropic, deepseek, openai_compatible, fake).
            $table->string('driver', 32)->nullable()->after('code');
            // null = default base URL from config (built-in providers).
            $table->string('base_url', 255)->nullable()->after('driver');
            // Encrypted with Laravel Crypt (APP_KEY). Never returned by the API.
            $table->text('api_key_encrypted')->nullable()->after('base_url');
            $table->string('api_key_last4', 8)->nullable()->after('api_key_encrypted');
            $table->boolean('is_custom')->default(false)->after('api_key_last4');
        });
        DB::table('ai_providers')->whereNull('driver')->update(['driver' => DB::raw('code')]);

        Schema::table('ai_model_prices', function (Blueprint $table) {
            // Off-peak rates apply OUTSIDE the peak windows (null = single rate).
            $table->unsignedBigInteger('offpeak_input_per_mtok')->nullable()->after('long_output_per_mtok');
            $table->unsignedBigInteger('offpeak_cached_input_per_mtok')->nullable()->after('offpeak_input_per_mtok');
            $table->unsignedBigInteger('offpeak_output_per_mtok')->nullable()->after('offpeak_cached_input_per_mtok');
            // [{"days":[1,2,3,4,5],"from":"01:00","to":"04:00"}, ...] — ISO weekdays, UTC times.
            $table->json('peak_windows')->nullable()->after('offpeak_output_per_mtok');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            $table->dropColumn(['offpeak_input_per_mtok', 'offpeak_cached_input_per_mtok', 'offpeak_output_per_mtok', 'peak_windows']);
        });
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn(['driver', 'base_url', 'api_key_encrypted', 'api_key_last4', 'is_custom']);
        });
    }
};
