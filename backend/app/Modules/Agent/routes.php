<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Agent\Http\Controllers\Admin\AdminAgentController;
use SiteXPost\Modules\Agent\Http\Controllers\AgentController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}/agent')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('agent.')
    ->group(function (): void {
        Route::get('tools', [AgentController::class, 'tools'])->name('tools');
        Route::get('sites/{site}/config', [AgentController::class, 'config'])->name('config.show');
        Route::put('sites/{site}/config', [AgentController::class, 'updateConfig'])->name('config.update');
        Route::post('sites/{site}/emergency-stop', [AgentController::class, 'emergencyStop'])->name('emergency_stop');
        Route::post('sites/{site}/runs', [AgentController::class, 'startRun'])->middleware('throttle:agent-run')->name('runs.store');
        Route::get('sites/{site}/schedules', [AgentController::class, 'schedules'])->name('schedules.index');
        Route::post('sites/{site}/schedules', [AgentController::class, 'storeSchedule'])->name('schedules.store');
        Route::patch('schedules/{automationSchedule}', [AgentController::class, 'updateSchedule'])->name('schedules.update');
        Route::get('runs', [AgentController::class, 'runs'])->name('runs.index');
        Route::get('runs/{agentRun}', [AgentController::class, 'run'])->name('runs.show');
        Route::post('runs/{agentRun}/pause', [AgentController::class, 'pauseRun'])->name('runs.pause');
        Route::post('runs/{agentRun}/resume', [AgentController::class, 'resumeRun'])->name('runs.resume');
        Route::post('runs/{agentRun}/cancel', [AgentController::class, 'cancelRun'])->name('runs.cancel');
        Route::get('tasks', [AgentController::class, 'tasks'])->name('tasks.index');
        Route::post('tasks/{agentTask}/approve', [AgentController::class, 'approveTask'])->name('tasks.approve');
        Route::post('tasks/{agentTask}/reject', [AgentController::class, 'rejectTask'])->name('tasks.reject');
        Route::post('tasks/{agentTask}/retry', [AgentController::class, 'retryTask'])->name('tasks.retry');
        Route::get('logs', [AgentController::class, 'logs'])->name('logs');
        Route::get('memories', [AgentController::class, 'memories'])->name('memories');
    });

// ---- Super Admin ----
Route::prefix('api/admin/v1/agent')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.agent.')
    ->group(function (): void {
        Route::get('overview', [AdminAgentController::class, 'overview'])->middleware('sitex.platform:platform.jobs.manage')->name('overview');
        Route::get('policy', [AdminAgentController::class, 'policy'])->middleware('sitex.platform:platform.ai.manage')->name('policy');
        Route::put('policy', [AdminAgentController::class, 'updatePolicy'])->middleware('sitex.platform:platform.ai.manage')->name('policy.update');
    });
