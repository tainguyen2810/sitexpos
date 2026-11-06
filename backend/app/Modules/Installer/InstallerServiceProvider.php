<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer;

use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Installer\Console\InstallStatus;
use SiteXPost\Modules\Installer\Console\InstallToken;

/**
 * Phase 9 — WordPress-style web installer (/install). Boots without a
 * database or APP_KEY; see INSTALLATION-WIZARD.md and INSTALLER-SECURITY.md.
 */
final class InstallerServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'installer');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallToken::class, InstallStatus::class]);
        }
    }
}
