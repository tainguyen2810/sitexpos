<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use SiteXPost\Modules\AiGateway\Registry\AiRegistry;

/**
 * Production-safe seeder: only mandatory system data (Phase 3: AI registry).
 * Demo data lives in DemoSeeder and is refused in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Phase 3: default AI providers/models/price versions/task routes (insert-only).
        $created = app(AiRegistry::class)->sync();
        $this->command?->info('AI registry: '.json_encode($created).'. For demo data (development only): php artisan db:seed --class=DemoSeeder');
    }
}
