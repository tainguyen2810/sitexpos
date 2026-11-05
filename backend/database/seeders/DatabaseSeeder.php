<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seeder: only mandatory system data (none in Phase 1).
 * Demo data lives in DemoSeeder and is refused in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('No system data to seed in Phase 1. For demo data (development only): php artisan db:seed --class=DemoSeeder');
    }
}
