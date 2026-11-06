<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\Installer\Support\EnvironmentChecker;
use SiteXPost\Modules\Installer\Support\InstallationState;

/**
 * Diagnostics for operators: installed signals, installer progress, requirement checks.
 */
final class InstallStatus extends Command
{
    protected $signature = 'sitex:install:status';

    protected $description = 'Show installation state and environment checks';

    public function handle(InstallationState $state, EnvironmentChecker $checker): int
    {
        $this->info('Installed: '.($state->isInstalled() ? 'yes ('.implode(', ', $state->signals()).')' : 'no'));
        $run = $state->progress()['run'] ?? null;
        if (is_array($run)) {
            $this->line('Last installer run: '.($run['status'] ?? '?').(isset($run['failed_step']) ? ' — failed at '.$run['failed_step'].': '.($run['error'] ?? '') : ''));
        }
        $rows = array_map(fn (array $c) => [$c['status'], $c['key'], $c['value']], $checker->run()['checks']);
        $this->table(['Status', 'Check', 'Value'], $rows);

        return self::SUCCESS;
    }
}
