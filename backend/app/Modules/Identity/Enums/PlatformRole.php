<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Enums;

use SiteXPost\Modules\Platform\Enums\PlatformPermission;

enum PlatformRole: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case Support = 'SUPPORT';
    case BillingAdmin = 'BILLING_ADMIN';

    /**
     * Permission matrix — SECURITY.md §3.1.
     *
     * @return list<PlatformPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => PlatformPermission::cases(),
            self::Support => [
                PlatformPermission::DashboardView,
                PlatformPermission::TenantsView,
                PlatformPermission::TenantsSuspend,
                PlatformPermission::UsersView,
                PlatformPermission::UsersSuspend,
                PlatformPermission::Impersonate,
                PlatformPermission::AuditView,
            ],
            self::BillingAdmin => [
                PlatformPermission::DashboardView,
                PlatformPermission::TenantsView,
                PlatformPermission::UsersView,
                PlatformPermission::BillingManage,
                PlatformPermission::AuditViewBilling,
            ],
        };
    }

    public function allows(PlatformPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
