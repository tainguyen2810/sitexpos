<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Identity\Http\Controllers\MeController;
use SiteXPost\Modules\Identity\Http\Controllers\SessionController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api'])
    ->group(function (): void {
        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');

        Route::get('me/sessions', [SessionController::class, 'index'])->name('me.sessions.index');
        Route::delete('me/sessions/others', [SessionController::class, 'destroyOthers'])->name('me.sessions.others');
        Route::delete('me/sessions/{session}', [SessionController::class, 'destroy'])
            ->where('session', '[a-f0-9]{32}')
            ->name('me.sessions.destroy');
    });
