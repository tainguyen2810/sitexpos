<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\System\Support\SystemSettings;
use SiteXPost\Modules\System\Upgrade\UpgradeInspector;

/**
 * Read-only pre-flight for scripts/deploy/upgrade.sh (and humans over SSH):
 * exit 0 = safe to run `migrate --force`, 2 = blocked (destructive migration,
 * requirements, an upgrade already running), 1 = nothing to do.
 */
final class UpgradeCheck extends Command
{
    protected $signature = 'sitex:upgrade:check {--json : Print the full status as JSON} {--record : After a CLI upgrade, record the code version when nothing is pending}';

    protected $description = 'Check whether the pending upgrade can run safely (destructive migrations, requirements, backups)';

    public function handle(UpgradeInspector $inspector, SystemSettings $settings): int
    {
        $s = $inspector->status();
        if ($this->option('record')) {
            if ($s['migrations']['pending'] !== [] || $s['blockers'] !== []) {
                $this->error('Cannot record the version: migrations pending or blocked.');

                return 2;
            }
            $settings->set('system.version', $s['target_version'], null);
            Audit::record('system.upgraded', null, ['from' => $s['current_version'], 'to' => $s['target_version'], 'via' => 'cli'], null, null, 'system');
            $this->info('Recorded version '.$s['target_version'].'.');

            return self::SUCCESS;
        }
        if ($this->option('json')) {
            $this->line((string) json_encode(array_diff_key($s, ['runs' => 1, 'changelog' => 1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->line('Code version: '.$s['target_version'].' · database version: '.($s['current_version'] ?? '—'));
        foreach ($s['migrations']['pending'] as $m) {
            $this->line(($m['destructive'] ? '  [DESTRUCTIVE] ' : '  [additive]    ').$m['name']);
        }
        foreach ($s['requirements']['failed'] as $r) {
            $this->error('Requirement failed: '.$r['key'].' = '.$r['value']);
        }
        if (! $s['backup']['recent']) {
            $this->warn('No successful backup in the last 24 hours. Run: php artisan sitex:backup:run --verify');
        }
        if ($s['blockers'] !== []) {
            $this->error('Blocked: '.implode(', ', $s['blockers']).'. See docs/UPGRADE-GUIDE.md.');

            return 2;
        }
        if (! $s['upgrade_needed']) {
            $this->info('Nothing to upgrade.');

            return 1;
        }
        $this->info('OK: '.count($s['migrations']['pending']).' additive migration(s) can run.');

        return self::SUCCESS;
    }
}
