<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\System\Support\SystemSettings;

/**
 * Scheduler heartbeat (every minute). System Health reads its age to tell
 * whether cron + schedule:run is configured and alive.
 */
final class Heartbeat extends Command
{
    protected $signature = 'sitex:system:heartbeat';

    protected $description = 'Record that the scheduler is running';

    public function handle(SystemSettings $settings): int
    {
        $settings->set('system.heartbeat_at', now()->toIso8601ZuluString());

        return self::SUCCESS;
    }
}
