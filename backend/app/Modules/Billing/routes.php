<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Billing\Http\Controllers\Admin\AdminCatalogController;
use SiteXPost\Modules\Billing\Http\Controllers\Admin\AdminOperationsController;
use SiteXPost\Modules\Billing\Http\Controllers\Admin\AdminReportController;
use SiteXPost\Modules\Billing\Http\Controllers\BillingController;
use SiteXPost\Modules\Billing\Http\Controllers\CreditController;
use SiteXPost\Modules\Billing\Http\Controllers\OrderController;
use SiteXPost\Modules\Billing\Http\Controllers\WebhookController;

// ---- Customer API (tenant) — API-CONTRACTS.md §Billing ----
Route::prefix('api/v1/workspaces/{workspace}/billing')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('billing.')
    ->group(function (): void {
        Route::get('plans', [BillingController::class, 'plans'])->name('plans');
        Route::get('subscription', [BillingController::class, 'show'])->name('subscription');
        Route::get('subscription/history', [BillingController::class, 'history'])->name('subscription.history');
        Route::post('subscription/trial', [BillingController::class, 'trial'])->middleware(['sitex.verified', 'throttle:billing-orders'])->name('subscription.trial');
        Route::post('subscription/free', [BillingController::class, 'subscribeFree'])->middleware('throttle:billing-orders')->name('subscription.free');
        Route::post('subscription/checkout', [BillingController::class, 'checkout'])->middleware(['sitex.verified', 'throttle:billing-orders'])->name('subscription.checkout');
        Route::post('subscription/quote', [BillingController::class, 'quote'])->name('subscription.quote');
        Route::post('subscription/change', [BillingController::class, 'change'])->middleware(['sitex.verified', 'throttle:billing-orders'])->name('subscription.change');
        Route::delete('subscription/change', [BillingController::class, 'cancelChange'])->name('subscription.change.cancel');
        Route::post('subscription/cancel', [BillingController::class, 'cancel'])->name('subscription.cancel');
        Route::post('subscription/resume', [BillingController::class, 'resume'])->name('subscription.resume');
        Route::post('subscription/renew', [BillingController::class, 'renew'])->middleware(['sitex.verified', 'throttle:billing-orders'])->name('subscription.renew');
        Route::patch('subscription/settings', [BillingController::class, 'settings'])->name('subscription.settings');
        Route::get('usage', [BillingController::class, 'usage'])->name('usage');

        Route::get('credits', [CreditController::class, 'wallet'])->name('credits');
        Route::get('credits/transactions', [CreditController::class, 'transactions'])->name('credits.transactions');
        Route::get('credits/packages', [CreditController::class, 'packages'])->name('credits.packages');
        Route::post('credits/purchase', [CreditController::class, 'purchase'])->middleware(['sitex.verified', 'throttle:billing-orders'])->name('credits.purchase');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{paymentOrder}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{paymentOrder}/transferred', [OrderController::class, 'transferred'])->name('orders.transferred');
        Route::post('orders/{paymentOrder}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::get('documents', [OrderController::class, 'documents'])->name('documents.index');
        Route::get('documents/{billingDocument}', [OrderController::class, 'document'])->name('documents.show');
        Route::get('profile', [OrderController::class, 'profile'])->name('profile');
        Route::put('profile', [OrderController::class, 'updateProfile'])->name('profile.update');
    });

// ---- Payment webhooks (public; authenticity = adapter signature) ----
Route::post('api/v1/billing/webhooks/{provider}', WebhookController::class)
    ->where('provider', '[a-z0-9_-]{2,40}')
    ->middleware(['api', 'throttle:billing-webhook'])->name('billing.webhook');

// ---- Super Admin ----
Route::prefix('api/admin/v1/billing')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api', 'sitex.platform:platform.billing.manage'])
    ->name('admin.billing.')
    ->group(function (): void {
        Route::get('plans', [AdminCatalogController::class, 'plans'])->name('plans.index');
        Route::post('plans', [AdminCatalogController::class, 'storePlan'])->name('plans.store');
        Route::patch('plans/{plan}', [AdminCatalogController::class, 'updatePlan'])->name('plans.update');
        Route::delete('plans/{plan}', [AdminCatalogController::class, 'destroyPlan'])->name('plans.destroy');
        Route::post('plans/{plan}/versions', [AdminCatalogController::class, 'storeVersion'])->name('versions.store');
        Route::patch('plan-versions/{version}', [AdminCatalogController::class, 'updateVersion'])->name('versions.update');
        Route::post('plan-versions/{version}/publish', [AdminCatalogController::class, 'publishVersion'])->name('versions.publish');
        Route::get('credit-packages', [AdminCatalogController::class, 'packages'])->name('packages.index');
        Route::post('credit-packages', [AdminCatalogController::class, 'storePackage'])->name('packages.store');
        Route::patch('credit-packages/{package}', [AdminCatalogController::class, 'updatePackage'])->name('packages.update');
        Route::get('credit-policies', [AdminCatalogController::class, 'policies'])->name('policies.index');
        Route::post('credit-policies', [AdminCatalogController::class, 'storePolicy'])->name('policies.store');

        Route::get('subscriptions', [AdminOperationsController::class, 'subscriptions'])->name('subscriptions.index');
        Route::get('subscriptions/{subscription}', [AdminOperationsController::class, 'subscription'])->name('subscriptions.show');
        Route::post('subscriptions/{subscription}/actions', [AdminOperationsController::class, 'subscriptionAction'])->name('subscriptions.action');
        Route::get('orders', [AdminOperationsController::class, 'orders'])->name('orders.index');
        Route::get('orders/{order}', [AdminOperationsController::class, 'order'])->name('orders.show');
        Route::post('orders/{order}/manual-payment', [AdminOperationsController::class, 'manualPayment'])->name('orders.manual');
        Route::post('orders/{order}/cancel', [AdminOperationsController::class, 'cancelOrder'])->name('orders.cancel');
        Route::post('orders/{order}/refunds', [AdminOperationsController::class, 'requestRefund'])->name('refunds.store');
        Route::get('transactions', [AdminOperationsController::class, 'transactions'])->name('transactions.index');
        Route::post('transactions/{transaction}/approve', [AdminOperationsController::class, 'approveTransaction'])->name('transactions.approve');
        Route::post('transactions/{transaction}/reject', [AdminOperationsController::class, 'rejectTransaction'])->name('transactions.reject');
        Route::get('refunds', [AdminOperationsController::class, 'refunds'])->name('refunds.index');
        Route::post('refunds/{refund}/complete', [AdminOperationsController::class, 'completeRefund'])->name('refunds.complete');
        Route::get('workspaces/{workspace}/wallet', [AdminOperationsController::class, 'wallet'])->name('wallet');
        Route::post('workspaces/{workspace}/credit-adjustments', [AdminOperationsController::class, 'adjust'])->name('adjustments.store');
        Route::get('credit-adjustments', [AdminOperationsController::class, 'adjustments'])->name('adjustments.index');
        Route::get('uncertain-usage', [AdminOperationsController::class, 'uncertainUsage'])->name('uncertain');

        Route::get('reports/revenue', [AdminReportController::class, 'revenue'])->name('reports.revenue');
        Route::get('reports/costs', [AdminReportController::class, 'costs'])->name('reports.costs');
        Route::get('reports/profitability', [AdminReportController::class, 'profitability'])->name('reports.profitability');
        Route::get('settings', [AdminReportController::class, 'settings'])->name('settings');
        Route::put('settings', [AdminReportController::class, 'updateSettings'])->name('settings.update');
        Route::get('providers', [AdminReportController::class, 'providers'])->name('providers.index');
        Route::put('providers/{code}', [AdminReportController::class, 'upsertProvider'])->name('providers.upsert');
    });
