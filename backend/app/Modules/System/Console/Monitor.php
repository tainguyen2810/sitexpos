<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\System\Health\HealthChecker;
use SiteXPost\Modules\System\Jobs\SendSystemAlert;
use SiteXPost\Modules\System\Monitoring\SslProbe;
use SiteXPost\Modules\System\Support\SystemSettings;
use Throwable;

/**
 * Monitoring loop (every 5 minutes, MONITORING-RUNBOOK.md): refreshes the SSL
 * expiry (hourly), evaluates System Health and queues alerts on status
 * changes (ok→warning/critical, warning→critical), repeats critical alerts
 * after the cooldown, and announces recoveries.
 */
final class Monitor extends Command
{
    /** Checks that describe incidents (configuration gaps are shown in the Setup Wizard instead). */
    public const ALERTABLE = ['application', 'database', 'redis', 'horizon', 'scheduler', 'queue', 'storage', 'ai_providers', 'ai_spending', 'payments', 'wordpress', 'backup', 'ssl'];

    private const RANK = ['ok' => 0, 'unknown' => 0, 'warning' => 1, 'critical' => 2];

    protected $signature = 'sitex:system:monitor {--no-ssl : skip the SSL probe}';

    protected $description = 'Evaluate system health and send alerts';

    public function handle(HealthChecker $health, SystemSettings $settings, SslProbe $ssl): int
    {
        if (! $this->option('no-ssl') && str_starts_with((string) config('app.url'), 'https://')) {
            $last = $settings->get('system.ssl');
            $checked = is_array($last) && isset($last['checked_at']) ? Carbon::parse((string) $last['checked_at']) : null;
            if ($checked === null || $checked->lt(now()->subHour())) {
                try {
                    $settings->set('system.ssl', $ssl->check((string) config('app.url')));
                } catch (Throwable) {
                    // probe failure is visible as "unknown" on the health page
                }
            }
        }

        $result = $health->run(self::ALERTABLE);
        $previous = (array) $settings->get('monitor.state');
        $state = [];
        $queued = 0;
        foreach ($result['checks'] as $check) {
            $key = $check['key'];
            $now = $check['status'];
            $before = (string) ($previous[$key] ?? 'ok');
            $state[$key] = $now;

            if ((self::RANK[$now] ?? 0) > (self::RANK[$before] ?? 0) || $now === 'critical') {
                SendSystemAlert::dispatch($key, $now, $check['summary'], false);
                $queued++;
            } elseif ($now === 'ok' && (self::RANK[$before] ?? 0) > 0) {
                SendSystemAlert::dispatch($key, 'recovered', $check['summary'], true);
                $queued++;
            }
        }
        $settings->set('monitor.state', $state);
        $this->info('Health: '.$result['status'].' · alerts queued: '.$queued);

        return self::SUCCESS;
    }
}
