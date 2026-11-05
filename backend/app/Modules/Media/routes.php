<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Media\Http\Controllers\Admin\AdminMediaController;
use SiteXPost\Modules\Media\Http\Controllers\ImageGenerationController;
use SiteXPost\Modules\Media\Http\Controllers\MediaController;
use SiteXPost\Modules\Media\Http\Controllers\PlacementController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}/media')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('media.')
    ->group(function (): void {
        Route::get('assets', [MediaController::class, 'index'])->name('assets.index');
        Route::post('assets', [MediaController::class, 'store'])->middleware(['sitex.verified', 'throttle:media-upload'])->name('assets.store');
        Route::get('assets/{asset}', [MediaController::class, 'show'])->name('assets.show');
        Route::patch('assets/{asset}', [MediaController::class, 'update'])->name('assets.update');
        Route::delete('assets/{asset}', [MediaController::class, 'destroy'])->name('assets.destroy');
        Route::post('assets/{asset}/derive', [MediaController::class, 'derive'])->middleware('throttle:media-upload')->name('assets.derive');
        Route::get('sources', [MediaController::class, 'sources'])->name('sources');
        Route::post('sources/sync', [MediaController::class, 'sync'])->middleware('throttle:media-upload')->name('sources.sync');
        Route::post('infographics', [MediaController::class, 'infographic'])->middleware('throttle:media-upload')->name('infographics.store');
        Route::get('usage', [MediaController::class, 'usage'])->name('usage');

        Route::post('generations/preview', [ImageGenerationController::class, 'preview'])->name('generations.preview');
        Route::get('generations', [ImageGenerationController::class, 'index'])->name('generations.index');
        Route::post('generations', [ImageGenerationController::class, 'store'])->middleware(['sitex.verified', 'throttle:media-ai'])->name('generations.store');
        Route::get('generations/{imageJob}', [ImageGenerationController::class, 'show'])->name('generations.show');
        Route::post('generations/{imageJob}/regenerate', [ImageGenerationController::class, 'regenerate'])->middleware(['sitex.verified', 'throttle:media-ai'])->name('generations.regenerate');

        Route::get('articles/{articleRef}/placements', [PlacementController::class, 'index'])->name('placements.index');
        Route::post('articles/{articleRef}/placements/suggest', [PlacementController::class, 'suggest'])->name('placements.suggest');
        Route::put('articles/{articleRef}/placements', [PlacementController::class, 'save'])->name('placements.save');
    });

// Signed, expiring file URLs (no session; signature = authorization).
Route::get('api/v1/media-files/{workspace}/{asset}/{variant}', [MediaController::class, 'file'])
    ->middleware(['signed', 'throttle:media-files'])->name('media.file');

// ---- Super Admin ----
Route::prefix('api/admin/v1/media')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.media.')
    ->group(function (): void {
        Route::get('overview', [AdminMediaController::class, 'overview'])->middleware('sitex.platform:platform.ai.view_costs')->name('overview');
        Route::get('limits', [AdminMediaController::class, 'limits'])->middleware('sitex.platform:platform.ai.manage')->name('limits');
        Route::put('limits', [AdminMediaController::class, 'updateLimits'])->middleware('sitex.platform:platform.ai.manage')->name('limits.update');
    });
