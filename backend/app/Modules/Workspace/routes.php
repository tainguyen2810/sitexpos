<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Workspace\Http\Controllers\DashboardController;
use SiteXPost\Modules\Workspace\Http\Controllers\InvitationController;
use SiteXPost\Modules\Workspace\Http\Controllers\JobRunController;
use SiteXPost\Modules\Workspace\Http\Controllers\MemberController;
use SiteXPost\Modules\Workspace\Http\Controllers\PublicInvitationController;
use SiteXPost\Modules\Workspace\Http\Controllers\WorkspaceAuditLogController;
use SiteXPost\Modules\Workspace\Http\Controllers\WorkspaceController;

// Public invitation preview (token is the secret).
Route::prefix('api/v1')
    ->middleware(['api', 'throttle:invitation-public'])
    ->get('invitations/{token}', [PublicInvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('invitations.show');

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api'])
    ->group(function (): void {
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('sitex.verified')->name('workspaces.store');

        Route::post('invitations/{token}/accept', [PublicInvitationController::class, 'accept'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware(['sitex.verified', 'throttle:invitation-public'])
            ->name('invitations.accept');

        // ---- Tenant routes: tenant resolved server-side from {workspace} + membership ----
        Route::prefix('workspaces/{workspace}')
            ->middleware(['sitex.tenant', 'throttle:workspace'])
            ->scopeBindings()
            ->group(function (): void {
                Route::get('/', [WorkspaceController::class, 'show'])->name('workspaces.show');
                Route::patch('/', [WorkspaceController::class, 'update'])->name('workspaces.update');
                Route::delete('/', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
                Route::post('transfer-ownership', [WorkspaceController::class, 'transferOwnership'])->name('workspaces.transfer');

                Route::get('dashboard', DashboardController::class)->name('workspaces.dashboard');

                Route::get('members', [MemberController::class, 'index'])->name('workspaces.members.index');
                Route::patch('members/{member}', [MemberController::class, 'update'])->name('workspaces.members.update');
                Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('workspaces.members.destroy');

                Route::get('invitations', [InvitationController::class, 'index'])->name('workspaces.invitations.index');
                Route::post('invitations', [InvitationController::class, 'store'])->name('workspaces.invitations.store');
                Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('workspaces.invitations.destroy');

                Route::get('audit-logs', WorkspaceAuditLogController::class)->name('workspaces.audit-logs');
                Route::get('job-runs/{jobRun}', [JobRunController::class, 'show'])->name('workspaces.job-runs.show');
            });
    });
