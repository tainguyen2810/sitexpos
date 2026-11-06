<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Contracts\UsageMeter;
use SiteXPost\Modules\Billing\Console\AggregateCosts;
use SiteXPost\Modules\Billing\Console\ExpireCredits;
use SiteXPost\Modules\Billing\Console\RunLifecycle;
use SiteXPost\Modules\Billing\Console\VerifyLedger;
use SiteXPost\Modules\Billing\Contracts\CreditMeter;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Billing\Credits\CreditUsageMeter;
use SiteXPost\Modules\Billing\Entitlements\EntitlementEngine;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Enums\Permission;

/**
 * Phase 7 — Billing, subscriptions, entitlements, AI credits, payments.
 */
final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Entitlements::class, EntitlementEngine::class);
        $this->app->bind(CreditMeter::class, CreditUsageMeter::class);
        // Replaces the AI Gateway's NullUsageMeter: AI calls reserve/settle credits.
        $this->app->bind(UsageMeter::class, CreditUsageMeter::class);
    }

    protected function bootModule(): void
    {
        // Every member can see the plan, credit balance and usage; only
        // billing.manage (OWNER) can buy, change, cancel or see payments.
        Gate::define('billing.view', fn (User $user): bool => app(TenantContext::class)->can(Permission::WorkspaceView));
        Gate::define('billing.manage', fn (User $user): bool => app(TenantContext::class)->can(Permission::BillingManage));

        RateLimiter::for('billing-orders', static function (Request $request): Limit {
            $workspace = $request->route('workspace');
            $ws = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return Limit::perMinute((int) config('sitex.rate_limits.billing_orders_per_minute', 10))
                ->by('billing-orders:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$ws);
        });
        RateLimiter::for('billing-webhook', static fn (Request $request): Limit => Limit::perMinute((int) config('sitex.rate_limits.billing_webhooks_per_minute', 120))
            ->by('billing-webhook:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([RunLifecycle::class, ExpireCredits::class, AggregateCosts::class, VerifyLedger::class]);
        }
    }
}
