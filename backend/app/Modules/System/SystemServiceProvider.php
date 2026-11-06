<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System;

use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\System\Support\SystemSettings;

/**
 * Phase 9 — platform operations: settings, setup wizard, health, upgrade,
 * backups, monitoring. Platform-level module (like Platform): cross-tenant
 * reads are read-only aggregates through TenantContext::bypass().
 */
final class SystemServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SystemSettings::class);
    }
}
