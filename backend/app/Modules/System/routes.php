<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\System\Http\Controllers\OperationsController;
use SiteXPost\Modules\System\Http\Controllers\SystemController;

$p = static fn (string $permission): string => 'sitex.platform:'.$permission;

Route::prefix('api/admin/v1/system')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.system.')
    ->group(function () use ($p): void {
        Route::get('health', [SystemController::class, 'health'])->middleware($p('platform.system.view'))->name('health');
        Route::get('alerts', [SystemController::class, 'alerts'])->middleware($p('platform.system.view'))->name('alerts');

        Route::middleware($p('platform.system.manage'))->group(function (): void {
            Route::get('setup', [SystemController::class, 'setup'])->name('setup');
            Route::post('setup/plans/draft', [SystemController::class, 'draftPlans'])->name('setup.plans');
            Route::post('setup/acknowledge', [SystemController::class, 'acknowledge'])->name('setup.acknowledge');
            Route::post('setup/complete', [SystemController::class, 'complete'])->name('setup.complete');

            Route::put('settings/general', [SystemController::class, 'updateGeneral'])->name('settings.general');
            Route::post('settings/logo', [SystemController::class, 'uploadLogo'])->name('settings.logo');
            Route::put('settings/mail', [SystemController::class, 'updateMail'])->name('settings.mail');
            Route::post('settings/mail/test', [SystemController::class, 'testMail'])->middleware('throttle:6,1')->name('settings.mail.test');
            Route::put('settings/google', [SystemController::class, 'updateGoogle'])->name('settings.google');
            Route::post('settings/google/test', [SystemController::class, 'testGoogle'])->middleware('throttle:6,1')->name('settings.google.test');
            Route::put('settings/alerts', [SystemController::class, 'updateAlerts'])->name('settings.alerts');
            Route::post('settings/alerts/test', [SystemController::class, 'testAlert'])->middleware('throttle:6,1')->name('settings.alerts.test');
            Route::put('settings/backup', [SystemController::class, 'updateBackup'])->name('settings.backup');
            Route::get('plugin/download', [SystemController::class, 'plugin'])->name('plugin');

            Route::get('upgrade', [OperationsController::class, 'upgrade'])->name('upgrade');
            Route::post('upgrade', [OperationsController::class, 'startUpgrade'])->middleware('throttle:6,1')->name('upgrade.start');
            Route::get('backups', [OperationsController::class, 'backups'])->name('backups');
            Route::post('backups', [OperationsController::class, 'startBackup'])->middleware('throttle:6,1')->name('backups.start');
            Route::post('backups/{backup}/verify', [OperationsController::class, 'verifyBackup'])->middleware('throttle:6,1')->name('backups.verify');
        });
    });
