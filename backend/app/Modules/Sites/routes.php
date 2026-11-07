<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Sites\Http\Controllers\ConnectorController;
use SiteXPost\Modules\Sites\Http\Controllers\PluginDownloadController;
use SiteXPost\Modules\Sites\Http\Controllers\SiteActionController;
use SiteXPost\Modules\Sites\Http\Controllers\SiteController;
use SiteXPost\Modules\Sites\Http\Controllers\SiteDataController;
use SiteXPost\Modules\Sites\Http\Middleware\LimitConnectorBody;
use SiteXPost\Modules\Sites\Http\Middleware\VerifyConnectorSignature;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
        Route::post('sites', [SiteController::class, 'store'])->middleware('sitex.verified')->name('sites.store');
        Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
        Route::patch('sites/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

        Route::post('sites/{site}/pairing-code', [SiteActionController::class, 'pairingCode'])->name('sites.pairing-code');
        Route::post('sites/{site}/health-check', [SiteActionController::class, 'healthCheck'])->name('sites.health-check');
        Route::post('sites/{site}/sync', [SiteActionController::class, 'sync'])->name('sites.sync');
        Route::post('sites/{site}/test-publish', [SiteActionController::class, 'testPublish'])->name('sites.test-publish');
        Route::post('sites/{site}/rotate-keys', [SiteActionController::class, 'rotateKeys'])->name('sites.rotate-keys');
        Route::post('sites/{site}/revoke', [SiteActionController::class, 'revoke'])->name('sites.revoke');

        Route::get('sites/{site}/taxonomies', [SiteDataController::class, 'taxonomies'])->name('sites.taxonomies');
        Route::get('sites/{site}/content', [SiteDataController::class, 'content'])->name('sites.content');
        Route::get('sites/{site}/authors', [SiteDataController::class, 'authors'])->name('sites.authors');
        Route::get('sites/{site}/logs', [SiteDataController::class, 'logs'])->name('sites.logs');
        Route::get('sites/{site}/health-checks', [SiteDataController::class, 'healthChecks'])->name('sites.health-checks');
    });

// ---- WordPress plugin download (public: the zip holds no secret) ----
Route::get('api/v1/plugin/sitex-post.zip', PluginDownloadController::class)
    ->middleware('throttle:plugin-download')
    ->name('plugin.download');

// ---- Connector API (called by the WordPress plugin; no cookies, no CSRF) ----
Route::prefix('connector/v1')
    ->middleware([LimitConnectorBody::class])
    ->group(function (): void {
        Route::post('pair/preview', [ConnectorController::class, 'preview'])->middleware('throttle:connector-pairing')->name('connector.pair.preview');
        Route::post('pair', [ConnectorController::class, 'pair'])->middleware('throttle:connector-pairing')->name('connector.pair');

        Route::middleware([VerifyConnectorSignature::class, 'throttle:connector-site'])->group(function (): void {
            Route::post('heartbeat', [ConnectorController::class, 'heartbeat'])->name('connector.heartbeat');
            Route::post('revoke', [ConnectorController::class, 'revoke'])->name('connector.revoke');
        });
    });
