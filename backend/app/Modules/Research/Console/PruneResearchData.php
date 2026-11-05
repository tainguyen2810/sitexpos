<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Research\Support\ResearchSettings;

/**
 * Retention: page snapshots (competitor/site extracts) and read notifications.
 * Keywords, ideas, briefs and run history are kept.
 */
final class PruneResearchData extends Command
{
    protected $signature = 'sitex:research:prune';

    protected $description = 'Delete old research page snapshots and read notifications';

    public function handle(ResearchSettings $settings): int
    {
        $days = max(7, (int) $settings->global('snapshot_retention_days'));
        $snapshots = DB::table('source_snapshots')->where('fetched_at', '<', now()->subDays($days))->delete();
        $notes = DB::table('research_notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(180))->delete();
        $this->info("Pruned {$snapshots} snapshots, {$notes} notifications.");

        return self::SUCCESS;
    }
}
