<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Publishing\Http\Controllers\Admin\AdminPublishingController;
use SiteXPost\Modules\Publishing\Http\Controllers\BatchController;
use SiteXPost\Modules\Publishing\Http\Controllers\PublishingController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}/publishing')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('publishing.')
    ->group(function (): void {
        Route::get('articles', [PublishingController::class, 'articles'])->name('articles.index');
        Route::get('articles/{articleRef}/preview', [PublishingController::class, 'preview'])->name('articles.preview');

        Route::get('jobs', [PublishingController::class, 'index'])->name('jobs.index');
        Route::post('jobs', [PublishingController::class, 'store'])->middleware(['sitex.verified', 'throttle:publishing'])->name('jobs.store');
        Route::get('jobs/{publishingJob}', [PublishingController::class, 'show'])->name('jobs.show');
        Route::get('jobs/{publishingJob}/html', [PublishingController::class, 'html'])->name('jobs.html');
        Route::post('jobs/{publishingJob}/recheck', [PublishingController::class, 'recheck'])->middleware('throttle:publishing')->name('jobs.recheck');
        Route::post('jobs/{publishingJob}/approve', [PublishingController::class, 'approve'])->middleware(['sitex.verified', 'throttle:publishing'])->name('jobs.approve');
        Route::post('jobs/{publishingJob}/reschedule', [PublishingController::class, 'reschedule'])->middleware('throttle:publishing')->name('jobs.reschedule');
        Route::post('jobs/{publishingJob}/cancel', [PublishingController::class, 'cancel'])->name('jobs.cancel');
        Route::post('jobs/{publishingJob}/retry', [PublishingController::class, 'retry'])->middleware(['sitex.verified', 'throttle:publishing'])->name('jobs.retry');

        Route::get('calendar', [PublishingController::class, 'calendar'])->name('calendar');
        Route::get('conflicts', [PublishingController::class, 'conflicts'])->name('conflicts.index');
        Route::get('conflicts/{conflict}', [PublishingController::class, 'conflict'])->name('conflicts.show');
        Route::post('conflicts/{conflict}/resolve', [PublishingController::class, 'resolve'])->middleware(['sitex.verified', 'throttle:publishing'])->name('conflicts.resolve');
        Route::get('mappings', [PublishingController::class, 'mappings'])->name('mappings.index');
        Route::post('mappings/{mapping}/check', [PublishingController::class, 'checkMapping'])->middleware('throttle:publishing')->name('mappings.check');
        Route::get('sites/{site}/policy', [PublishingController::class, 'policy'])->name('policy.show');
        Route::put('sites/{site}/policy', [PublishingController::class, 'updatePolicy'])->name('policy.update');

        Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
        Route::post('batches/preview', [BatchController::class, 'preview'])->name('batches.preview');
        Route::post('batches', [BatchController::class, 'store'])->middleware(['sitex.verified', 'throttle:publishing'])->name('batches.store');
        Route::get('batches/{publishingBatch}', [BatchController::class, 'show'])->name('batches.show');
        Route::post('batches/{publishingBatch}/pause', [BatchController::class, 'pause'])->name('batches.pause');
        Route::post('batches/{publishingBatch}/resume', [BatchController::class, 'resume'])->name('batches.resume');
        Route::post('batches/{publishingBatch}/cancel', [BatchController::class, 'cancel'])->name('batches.cancel');
    });

// ---- Super Admin ----
Route::prefix('api/admin/v1/publishing')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.publishing.')
    ->group(function (): void {
        Route::get('overview', [AdminPublishingController::class, 'overview'])->middleware('sitex.platform:platform.jobs.manage')->name('overview');
        Route::get('limits', [AdminPublishingController::class, 'limits'])->middleware('sitex.platform:platform.jobs.manage')->name('limits');
        Route::put('limits', [AdminPublishingController::class, 'updateLimits'])->middleware('sitex.platform:platform.jobs.manage')->name('limits.update');
    });
