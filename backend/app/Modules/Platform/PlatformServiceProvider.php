<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform;

use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Platform\Console\MakeSuperAdmin;

final class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeSuperAdmin::class]);
        }

        // Horizon dashboard: SUPER_ADMIN with confirmed 2FA only.
        Gate::define('viewHorizon', static fn (?User $user): bool => $user !== null
            && $user->platform_role === PlatformRole::SuperAdmin
            && $user->hasConfirmedTwoFactor());
    }
}
