<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Seo\Http\Controllers\SeoController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}/seo')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('seo.')
    ->group(function (): void {
        Route::get('articles', [SeoController::class, 'articles'])->name('articles.index');
        Route::get('articles/{articleRef}/metadata', [SeoController::class, 'metadata'])->name('metadata.show');
        Route::post('articles/{articleRef}/metadata/generate', [SeoController::class, 'generateMetadata'])->name('metadata.generate');
        Route::put('articles/{articleRef}/metadata', [SeoController::class, 'updateMetadata'])->name('metadata.update');
        Route::get('articles/{articleRef}/audits', [SeoController::class, 'audits'])->name('audits.index');
        Route::post('articles/{articleRef}/audits', [SeoController::class, 'runAudit'])->middleware('throttle:seo-audit')->name('audits.store');
        Route::post('articles/{articleRef}/audits/link-check', [SeoController::class, 'checkLinks'])->middleware('throttle:seo-audit')->name('audits.link_check');
        Route::get('audits/{auditRun}', [SeoController::class, 'audit'])->name('audits.show');
        Route::get('articles/{articleRef}/schema', [SeoController::class, 'schema'])->name('schema.show');
        Route::get('articles/{articleRef}/links', [SeoController::class, 'links'])->name('links.index');
        Route::post('articles/{articleRef}/links/suggest', [SeoController::class, 'suggestLinks'])->middleware('throttle:seo-audit')->name('links.suggest');
        Route::patch('links/{suggestion}', [SeoController::class, 'decideLink'])->name('links.decide');
        Route::get('sites/{site}/config', [SeoController::class, 'siteConfig'])->name('sites.config');
        Route::put('sites/{site}/config', [SeoController::class, 'updateSiteConfig'])->name('sites.config.update');
    });
