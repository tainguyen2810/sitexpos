<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\AiGateway\Console\ExpireAiReservations;
use SiteXPost\Modules\AiGateway\Console\PruneAiRequests;
use SiteXPost\Modules\AiGateway\Console\SyncAiRegistry;
use SiteXPost\Modules\AiGateway\Providers\ProviderRegistry;
use SiteXPost\Modules\AiGateway\Workflows\AiTestWorkflow;
use SiteXPost\Modules\Workflows\Engine\WorkflowRegistry;

final class AiGatewayServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(WorkflowRegistry::class)->register(AiTestWorkflow::class);

        // Paid work: cap submissions per user per workspace (budget caps are the hard limit).
        RateLimiter::for('ai-tasks', static function (Request $request): Limit {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return Limit::perMinute((int) config('sitex.rate_limits.ai_tasks_per_minute', 20))
                ->by('ai-tasks:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey);
        });

        // Super Admin Test Connection / Test Model (real provider calls).
        RateLimiter::for('ai-admin-tests', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('ai-admin-tests:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAiRegistry::class, ExpireAiReservations::class, PruneAiRequests::class]);
        }
    }
}
