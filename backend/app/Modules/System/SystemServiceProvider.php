<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System;

use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\System\Console\BackupDecrypt;
use SiteXPost\Modules\System\Console\BackupPrune;
use SiteXPost\Modules\System\Console\BackupRun;
use SiteXPost\Modules\System\Console\BackupVerify;
use SiteXPost\Modules\System\Console\Heartbeat;
use SiteXPost\Modules\System\Console\Monitor;
use SiteXPost\Modules\System\Support\RuntimeConfig;
use SiteXPost\Modules\System\Support\SystemSettings;

/**
 * Phase 9 — platform operations: settings, setup wizard, health, upgrade,
 * backups, monitoring. Platform-level module (like Platform): its cross-tenant
 * figures are read-only aggregate counts, never tenant rows.
 */
final class SystemServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SystemSettings::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Heartbeat::class, Monitor::class, BackupRun::class, BackupVerify::class, BackupPrune::class, BackupDecrypt::class]);
        }

        // Settings saved in the Setup Wizard (SMTP, Google OAuth) override .env.
        if ((string) config('app.key') !== '' && ! $this->app->runningUnitTests()) {
            $this->app->make(RuntimeConfig::class)->apply();
        }
    }
}
