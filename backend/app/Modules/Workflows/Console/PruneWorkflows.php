<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneWorkflows extends Command
{
    protected $signature = 'sitex:workflows:prune';

    protected $description = 'Delete finished workflow runs and resolved dead letters older than the retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(7, (int) config('sitex.workflows.retention_days', 90)));
        $runs = DB::table('workflow_runs')->whereIn('status', ['succeeded', 'canceled'])->where('finished_at', '<', $cutoff)->delete();
        $dead = DB::table('dead_letters')->where('status', '!=', 'open')->where('updated_at', '<', $cutoff)->delete();
        $this->info("Pruned {$runs} runs, {$dead} dead letters.");

        return self::SUCCESS;
    }
}
