<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Platform\Http\Controllers\AdminAuditLogController;
use SiteXPost\Modules\Platform\Http\Controllers\AdminDashboardController;
use SiteXPost\Modules\Platform\Http\Controllers\AdminUserController;
use SiteXPost\Modules\Platform\Http\Controllers\AdminWorkspaceController;
use SiteXPost\Modules\Platform\Http\Controllers\ImpersonationController;
use SiteXPost\Modules\Platform\Http\Controllers\StaffController;

$p = static fn (string $permission): string => 'sitex.platform:'.$permission;

Route::prefix('api/admin/v1')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.')
    ->group(function () use ($p): void {
        Route::get('dashboard', AdminDashboardController::class)->middleware($p('platform.dashboard.view'))->name('dashboard');

        Route::get('workspaces', [AdminWorkspaceController::class, 'index'])->middleware($p('platform.tenants.view'))->name('workspaces.index');
        Route::get('workspaces/{workspace}', [AdminWorkspaceController::class, 'show'])->middleware($p('platform.tenants.view'))->name('workspaces.show');
        Route::post('workspaces/{workspace}/suspend', [AdminWorkspaceController::class, 'suspend'])->middleware($p('platform.tenants.suspend'))->name('workspaces.suspend');
        Route::post('workspaces/{workspace}/unsuspend', [AdminWorkspaceController::class, 'unsuspend'])->middleware($p('platform.tenants.suspend'))->name('workspaces.unsuspend');

        Route::get('users', [AdminUserController::class, 'index'])->middleware($p('platform.users.view'))->name('users.index');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->middleware($p('platform.users.view'))->name('users.show');
        Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->middleware($p('platform.users.suspend'))->name('users.suspend');
        Route::post('users/{user}/unsuspend', [AdminUserController::class, 'unsuspend'])->middleware($p('platform.users.suspend'))->name('users.unsuspend');

        Route::post('impersonations', [ImpersonationController::class, 'store'])->middleware($p('platform.impersonate'))->name('impersonations.store');
        Route::delete('impersonations/current', [ImpersonationController::class, 'destroy'])->name('impersonations.destroy');

        Route::get('staff', [StaffController::class, 'index'])->middleware($p('platform.staff.manage'))->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->middleware($p('platform.staff.manage'))->name('staff.store');
        Route::patch('staff/{user}', [StaffController::class, 'update'])->middleware($p('platform.staff.manage'))->name('staff.update');

        Route::get('audit-logs', AdminAuditLogController::class)->name('audit-logs');
    });
