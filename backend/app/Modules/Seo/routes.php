<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Seo\Http\Controllers\SeoController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}/seo')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('seo.')
    ->group(function (): void {
        Route::get('articles', [SeoController::class, 'articles'])->name('articles.index');
        Route::get('articles/{article}/metadata', [SeoController::class, 'metadata'])->name('metadata.show');
        Route::post('articles/{article}/metadata/generate', [SeoController::class, 'generateMetadata'])->name('metadata.generate');
        Route::put('articles/{article}/metadata', [SeoController::class, 'updateMetadata'])->name('metadata.update');
        Route::get('articles/{article}/audits', [SeoController::class, 'audits'])->name('audits.index');
        Route::post('articles/{article}/audits', [SeoController::class, 'runAudit'])->middleware('throttle:seo-audit')->name('audits.store');
        Route::post('articles/{article}/audits/link-check', [SeoController::class, 'checkLinks'])->middleware('throttle:seo-audit')->name('audits.link_check');
        Route::get('audits/{auditRun}', [SeoController::class, 'audit'])->name('audits.show');
        Route::get('articles/{article}/schema', [SeoController::class, 'schema'])->name('schema.show');
        Route::get('articles/{article}/links', [SeoController::class, 'links'])->name('links.index');
        Route::post('articles/{article}/links/suggest', [SeoController::class, 'suggestLinks'])->middleware('throttle:seo-audit')->name('links.suggest');
        Route::patch('links/{suggestion}', [SeoController::class, 'decideLink'])->name('links.decide');
        Route::get('sites/{site}/config', [SeoController::class, 'siteConfig'])->name('sites.config');
        Route::put('sites/{site}/config', [SeoController::class, 'updateSiteConfig'])->name('sites.config.update');
    });
