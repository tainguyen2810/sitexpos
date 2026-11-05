<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Workflows\Http\Controllers\AdminJobController;

// ---- Admin API: job monitor (SUPER_ADMIN, SUPPORT) ----
Route::prefix('api/admin/v1/jobs')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform:platform.jobs.manage', 'throttle:api'])
    ->name('admin.jobs.')
    ->group(function (): void {
        Route::get('overview', [AdminJobController::class, 'overview'])->name('overview');
        Route::get('workflows', [AdminJobController::class, 'workflows'])->name('workflows.index');
        Route::get('workflows/{run}', [AdminJobController::class, 'show'])->name('workflows.show');
        Route::post('workflows/{run}/retry', [AdminJobController::class, 'retry'])->name('workflows.retry');
        Route::post('workflows/{run}/cancel', [AdminJobController::class, 'cancel'])->name('workflows.cancel');
        Route::get('dead-letters', [AdminJobController::class, 'deadLetters'])->name('dead-letters.index');
        Route::post('dead-letters/{deadLetter}/retry', [AdminJobController::class, 'retryDeadLetter'])->name('dead-letters.retry');
        Route::post('dead-letters/{deadLetter}/discard', [AdminJobController::class, 'discardDeadLetter'])->name('dead-letters.discard');
    });
