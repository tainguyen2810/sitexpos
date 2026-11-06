<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Research\Console\DispatchResearchSchedules;
use SiteXPost\Modules\Research\Console\PruneResearchData;
use SiteXPost\Modules\Research\Console\SyncResearchRuns;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Research\Contracts\PublicWebFetcher;
use SiteXPost\Modules\Research\Crawler\PublicFetcher;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Policies\SeoCampaignPolicy;
use SiteXPost\Modules\Research\Support\PlannerDirectoryService;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Research\Workflows\ResearchDiscoveryWorkflow;
use SiteXPost\Modules\Workflows\Engine\WorkflowRegistry;

/**
 * Phase 4 — AI Topic Discovery & SEO Planner.
 */
final class ResearchServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        SeoCampaign::class => SeoCampaignPolicy::class,
    ];

    public function register(): void
    {
        $this->app->scoped(ResearchSettings::class);
        // Read-only facades for other modules (Phase 5 writer).
        $this->app->bind(PlannerDirectory::class, PlannerDirectoryService::class);
        $this->app->bind(PublicWebFetcher::class, PublicFetcher::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(WorkflowRegistry::class)->register(ResearchDiscoveryWorkflow::class);

        // Starting research is expensive (crawl + paid AI): cap per user per workspace.
        RateLimiter::for('research-runs', static function (Request $request): Limit {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return Limit::perMinute(6)->by('research-runs:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey);
        });
        RateLimiter::for('oauth-callback', static fn (Request $request): Limit => Limit::perMinute(20)->by('oauth-callback:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchResearchSchedules::class, SyncResearchRuns::class, PruneResearchData::class]);
        }
    }
}
