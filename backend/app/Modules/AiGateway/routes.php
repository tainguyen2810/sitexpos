<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\AiGateway\Http\Controllers\Admin\AdminAiBudgetController;
use SiteXPost\Modules\AiGateway\Http\Controllers\Admin\AdminAiModelController;
use SiteXPost\Modules\AiGateway\Http\Controllers\Admin\AdminAiProviderController;
use SiteXPost\Modules\AiGateway\Http\Controllers\Admin\AdminAiRequestController;
use SiteXPost\Modules\AiGateway\Http\Controllers\Admin\AdminAiRouteController;
use SiteXPost\Modules\AiGateway\Http\Controllers\Admin\AdminAiUsageController;
use SiteXPost\Modules\AiGateway\Http\Controllers\AiTaskController;
use SiteXPost\Modules\AiGateway\Http\Controllers\AiUsageController;

// ---- Customer API (tenant) ----
Route::prefix('api/v1/workspaces/{workspace}')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->group(function (): void {
        Route::get('ai/tasks', [AiTaskController::class, 'index'])->name('ai.tasks.index');
        Route::post('ai/tasks', [AiTaskController::class, 'store'])->middleware(['sitex.verified', 'throttle:ai-tasks'])->name('ai.tasks.store');
        Route::get('ai/tasks/{workflowRun}', [AiTaskController::class, 'show'])->name('ai.tasks.show');
        Route::post('ai/tasks/{workflowRun}/cancel', [AiTaskController::class, 'cancel'])->name('ai.tasks.cancel');
        Route::post('ai/tasks/{workflowRun}/retry', [AiTaskController::class, 'retry'])->middleware('throttle:ai-tasks')->name('ai.tasks.retry');
        Route::get('ai/usage', AiUsageController::class)->name('ai.usage');
    });

// ---- Admin API (Super Admin; cost views also BILLING_ADMIN) ----
$manage = 'sitex.platform:platform.ai.manage';
$costs = 'sitex.platform:platform.ai.view_costs';

Route::prefix('api/admin/v1/ai')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.ai.')
    ->group(function () use ($manage, $costs): void {
        Route::get('usage', AdminAiUsageController::class)->middleware($costs)->name('usage');
        Route::get('requests', [AdminAiRequestController::class, 'index'])->middleware($costs)->name('requests.index');

        Route::get('providers', [AdminAiProviderController::class, 'index'])->middleware($manage)->name('providers.index');
        Route::patch('providers/{provider}', [AdminAiProviderController::class, 'update'])->middleware($manage)->name('providers.update');
        Route::post('providers/{provider}/circuit/reset', [AdminAiProviderController::class, 'resetCircuit'])->middleware($manage)->name('providers.circuit.reset');

        Route::get('models', [AdminAiModelController::class, 'index'])->middleware($manage)->name('models.index');
        Route::post('models', [AdminAiModelController::class, 'store'])->middleware($manage)->name('models.store');
        Route::patch('models/{model}', [AdminAiModelController::class, 'update'])->middleware($manage)->name('models.update');
        Route::get('models/{model}/prices', [AdminAiModelController::class, 'prices'])->middleware($manage)->name('models.prices');
        Route::post('models/{model}/prices', [AdminAiModelController::class, 'storePrice'])->middleware($manage)->name('models.prices.store');
        Route::delete('models/{model}/prices/{price}', [AdminAiModelController::class, 'destroyPrice'])->middleware($manage)->name('models.prices.destroy');

        Route::get('routes', [AdminAiRouteController::class, 'index'])->middleware($manage)->name('routes.index');
        Route::patch('routes/{taskRoute}', [AdminAiRouteController::class, 'update'])->middleware($manage)->name('routes.update');

        Route::get('budgets', [AdminAiBudgetController::class, 'index'])->middleware($costs)->name('budgets.index');
        Route::put('budgets', [AdminAiBudgetController::class, 'upsert'])->middleware($manage)->name('budgets.upsert');
        Route::delete('budgets/{budget}', [AdminAiBudgetController::class, 'destroy'])->middleware($manage)->name('budgets.destroy');
    });
