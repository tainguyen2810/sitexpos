<?php

use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Platform\Enums\PlatformPermission;
use SiteXPost\Modules\Workspace\Enums\Permission;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

it('matches the tenant permission matrix (SECURITY.md §3.2)', function () {
    expect(WorkspaceRole::Owner->allows(Permission::BillingManage))->toBeTrue()
        ->and(WorkspaceRole::Owner->allows(Permission::WorkspaceTransfer))->toBeTrue()
        ->and(WorkspaceRole::Admin->allows(Permission::MembersManage))->toBeTrue()
        ->and(WorkspaceRole::Admin->allows(Permission::BillingManage))->toBeFalse()
        ->and(WorkspaceRole::Admin->allows(Permission::WorkspaceDelete))->toBeFalse()
        ->and(WorkspaceRole::Editor->allows(Permission::ContentEdit))->toBeTrue()
        ->and(WorkspaceRole::Editor->allows(Permission::MembersManage))->toBeFalse()
        ->and(WorkspaceRole::Editor->allows(Permission::SitesManage))->toBeFalse()
        ->and(WorkspaceRole::Viewer->allows(Permission::WorkspaceView))->toBeTrue()
        ->and(WorkspaceRole::Viewer->allows(Permission::ContentEdit))->toBeFalse()
        ->and(WorkspaceRole::Viewer->allows(Permission::AuditView))->toBeFalse();
});

it('never lets anyone assign OWNER and limits ADMIN to EDITOR/VIEWER', function () {
    foreach (WorkspaceRole::cases() as $role) {
        expect($role->canManage(WorkspaceRole::Owner))->toBeFalse();
    }

    expect(WorkspaceRole::Admin->canManage(WorkspaceRole::Admin))->toBeFalse()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Editor))->toBeTrue()
        ->and(WorkspaceRole::Owner->canManage(WorkspaceRole::Admin))->toBeTrue()
        ->and(WorkspaceRole::Editor->manageableRoles())->toBe([]);
});

it('matches the platform permission matrix (SECURITY.md §3.1)', function () {
    expect(PlatformRole::SuperAdmin->allows(PlatformPermission::StaffManage))->toBeTrue()
        ->and(PlatformRole::Support->allows(PlatformPermission::Impersonate))->toBeTrue()
        ->and(PlatformRole::Support->allows(PlatformPermission::StaffManage))->toBeFalse()
        ->and(PlatformRole::Support->allows(PlatformPermission::BillingManage))->toBeFalse()
        ->and(PlatformRole::BillingAdmin->allows(PlatformPermission::BillingManage))->toBeTrue()
        ->and(PlatformRole::BillingAdmin->allows(PlatformPermission::Impersonate))->toBeFalse()
        ->and(PlatformRole::BillingAdmin->allows(PlatformPermission::UsersSuspend))->toBeFalse();
});
