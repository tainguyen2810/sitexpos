<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Analytics\Http\Controllers\Admin\AdminAnalyticsController;
use SiteXPost\Modules\Analytics\Http\Controllers\ConnectionController;
use SiteXPost\Modules\Analytics\Http\Controllers\CopilotController;
use SiteXPost\Modules\Analytics\Http\Controllers\DashboardController;
use SiteXPost\Modules\Analytics\Http\Controllers\InsightController;
use SiteXPost\Modules\Analytics\Http\Controllers\RefreshController;
use SiteXPost\Modules\Analytics\Http\Controllers\ReportController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}/analytics')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('analytics.')
    ->group(function (): void {
        // Connections & sync
        Route::get('sites', [ConnectionController::class, 'sites'])->name('sites.index');
        Route::get('sites/{site}/connection', [ConnectionController::class, 'connection'])->name('sites.connection');
        Route::post('sites/{site}/connect', [ConnectionController::class, 'connect'])->middleware('throttle:analytics-sync')->name('sites.connect');
        Route::get('sites/{site}/properties', [ConnectionController::class, 'properties'])->middleware('throttle:analytics-sync')->name('sites.properties');
        Route::put('sites/{site}/property', [ConnectionController::class, 'selectProperty'])->middleware('throttle:analytics-sync')->name('sites.property');
        Route::delete('sites/{site}/connection', [ConnectionController::class, 'disconnect'])->name('sites.disconnect');
        Route::post('sites/{site}/sync', [ConnectionController::class, 'sync'])->middleware('throttle:analytics-sync')->name('sites.sync');
        Route::get('sync-runs', [ConnectionController::class, 'syncRuns'])->name('sync_runs.index');
        Route::get('settings', [ConnectionController::class, 'settings'])->name('settings.show');
        Route::put('settings', [ConnectionController::class, 'updateSettings'])->name('settings.update');

        // Dashboard & content intelligence
        Route::get('overview', [DashboardController::class, 'overview'])->name('overview');
        Route::get('queries', [DashboardController::class, 'queries'])->name('queries');
        Route::get('pages', [DashboardController::class, 'pages'])->name('pages');
        Route::get('dimensions', [DashboardController::class, 'dimensions'])->name('dimensions');
        Route::get('articles', [DashboardController::class, 'articles'])->name('articles.index');
        Route::get('articles/{articleRef}', [DashboardController::class, 'article'])->name('articles.show');
        Route::get('clusters', [DashboardController::class, 'clusters'])->name('clusters');
        Route::get('content', [DashboardController::class, 'content'])->name('content');
        Route::get('health', [DashboardController::class, 'health'])->name('health');

        // Insights
        Route::get('opportunities', [InsightController::class, 'opportunities'])->name('opportunities.index');
        Route::get('opportunities/{opportunity}', [InsightController::class, 'opportunity'])->name('opportunities.show');
        Route::patch('opportunities/{opportunity}', [InsightController::class, 'decideOpportunity'])->name('opportunities.decide');
        Route::get('decay', [InsightController::class, 'decay'])->name('decay.index');
        Route::patch('decay/{decay}', [InsightController::class, 'decideDecay'])->name('decay.decide');
        Route::get('recommendations', [InsightController::class, 'recommendations'])->name('recommendations.index');
        Route::patch('recommendations/{recommendation}', [InsightController::class, 'decideRecommendation'])->name('recommendations.decide');
        Route::get('experiments', [InsightController::class, 'experiments'])->name('experiments.index');
        Route::post('experiments', [InsightController::class, 'storeExperiment'])->name('experiments.store');
        Route::get('experiments/{experiment}', [InsightController::class, 'experiment'])->name('experiments.show');
        Route::post('experiments/{experiment}/cancel', [InsightController::class, 'cancelExperiment'])->name('experiments.cancel');

        // Content refresh
        Route::get('refresh-jobs', [RefreshController::class, 'index'])->name('refresh.index');
        Route::post('refresh-jobs', [RefreshController::class, 'store'])->middleware('throttle:analytics-sync')->name('refresh.store');
        Route::get('refresh-jobs/{refreshJob}', [RefreshController::class, 'show'])->name('refresh.show');
        Route::post('refresh-jobs/{refreshJob}/approve', [RefreshController::class, 'approve'])->name('refresh.approve');
        Route::post('refresh-jobs/{refreshJob}/publish', [RefreshController::class, 'publish'])->name('refresh.publish');
        Route::post('refresh-jobs/{refreshJob}/cancel', [RefreshController::class, 'cancel'])->name('refresh.cancel');
        Route::post('refresh-jobs/{refreshJob}/poll', [RefreshController::class, 'retry'])->name('refresh.poll');

        // Reports
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:analytics-sync')->name('reports.store');
        Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('reports/{report}/csv', [ReportController::class, 'csv'])->name('reports.csv');
        Route::post('reports/{report}/email', [ReportController::class, 'email'])->middleware('throttle:analytics-sync')->name('reports.email');

        // Copilot (read only)
        Route::post('copilot', [CopilotController::class, 'ask'])->middleware('throttle:copilot')->name('copilot.ask');
        Route::get('copilot', [CopilotController::class, 'history'])->name('copilot.history');
        Route::get('copilot/{copilotQuery}', [CopilotController::class, 'show'])->name('copilot.show');
    });

// ---- Super Admin ----
Route::prefix('api/admin/v1/analytics')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.analytics.')
    ->group(function (): void {
        Route::get('overview', [AdminAnalyticsController::class, 'overview'])->middleware('sitex.platform:platform.jobs.manage')->name('overview');
    });
