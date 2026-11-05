<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneAiRequests extends Command
{
    protected $signature = 'sitex:ai:prune';

    protected $description = 'Delete AI request logs and settled reservations older than the retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(30, (int) config('sitex.ai.request_log_retention_days', 90)));
        $requests = DB::table('ai_requests')->where('created_at', '<', $cutoff)->delete();
        $reservations = DB::table('ai_budget_reservations')->where('status', '!=', 'reserved')->where('created_at', '<', $cutoff)->delete();
        $this->info("Pruned {$requests} AI requests, {$reservations} reservations.");

        return self::SUCCESS;
    }
}
