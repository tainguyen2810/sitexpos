<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Installer\Http\Controllers\InstallerController;
use SiteXPost\Modules\Installer\Http\Middleware\InstallerGuard;

/*
 * Web installer. Deliberately NOT in the "web" group: no session, no cookies,
 * no APP_KEY or database needed to render the first steps. Authentication is
 * the one-time setup token (InstallerGuard).
 */
Route::get('install', [InstallerController::class, 'page'])
    ->middleware(InstallerGuard::class.':page')->name('installer.page');

Route::prefix('install/api')->middleware(InstallerGuard::class.':api')->name('installer.')->group(function (): void {
    Route::get('state', [InstallerController::class, 'state'])->name('state');
    Route::get('environment', [InstallerController::class, 'environment'])->name('environment');
    Route::post('database/test', [InstallerController::class, 'testDatabase'])->name('database.test');
    Route::put('database', [InstallerController::class, 'saveDatabase'])->name('database.save');
    Route::put('application', [InstallerController::class, 'saveApplication'])->name('application.save');
    Route::post('redis/test', [InstallerController::class, 'testRedis'])->name('redis.test');
    Route::put('queue', [InstallerController::class, 'saveQueue'])->name('queue.save');
    Route::put('admin', [InstallerController::class, 'saveAdmin'])->name('admin.save');
    Route::post('run', [InstallerController::class, 'run'])->name('run');
    Route::get('templates/{name}', [InstallerController::class, 'template'])->whereIn('name', ['supervisor-horizon', 'supervisor-queue-work', 'cron', 'nginx-api'])->name('template');
    Route::get('diagnostics', [InstallerController::class, 'diagnostics'])->name('diagnostics');
});
