<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Enums;

enum PlatformPermission: string
{
    case DashboardView = 'platform.dashboard.view';
    case TenantsView = 'platform.tenants.view';
    case TenantsSuspend = 'platform.tenants.suspend';
    case UsersView = 'platform.users.view';
    case UsersSuspend = 'platform.users.suspend';
    case Impersonate = 'platform.impersonate';
    case StaffManage = 'platform.staff.manage';
    case AuditView = 'platform.audit.view';
    case AuditViewBilling = 'platform.audit.view_billing';
    case BillingManage = 'platform.billing.manage';
    case AiManage = 'platform.ai.manage';
    case AiCostsView = 'platform.ai.view_costs';
    case JobsManage = 'platform.jobs.manage';
}
