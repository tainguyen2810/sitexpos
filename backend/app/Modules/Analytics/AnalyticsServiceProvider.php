<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Analytics\Console\PruneAnalytics;
use SiteXPost\Modules\Analytics\Console\ScheduleAnalyticsSync;
use SiteXPost\Modules\Analytics\Console\TickContentRefresh;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSetting;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Domain\ContentDecayEvent;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\CopilotQuery;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Domain\SeoReport;
use SiteXPost\Modules\Analytics\Policies\AnalyticsPolicy;

/**
 * Phase 8 — SEO Analytics & Content Intelligence (ANALYTICS-ARCHITECTURE.md,
 * GSC-INTEGRATION.md, SEO-OPPORTUNITY-RULES.md).
 */
final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        AnalyticsProperty::class => AnalyticsPolicy::class,
        AnalyticsSetting::class => AnalyticsPolicy::class,
        AnalyticsSyncRun::class => AnalyticsPolicy::class,
        SeoOpportunity::class => AnalyticsPolicy::class,
        ContentDecayEvent::class => AnalyticsPolicy::class,
        SeoRecommendation::class => AnalyticsPolicy::class,
        SeoExperiment::class => AnalyticsPolicy::class,
        ContentRefreshJob::class => AnalyticsPolicy::class,
        SeoHealthScore::class => AnalyticsPolicy::class,
        SeoReport::class => AnalyticsPolicy::class,
        CopilotQuery::class => AnalyticsPolicy::class,
    ];

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ScheduleAnalyticsSync::class, TickContentRefresh::class, PruneAnalytics::class]);
        }
        $key = static function (Request $request, string $prefix): string {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return $prefix.':'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey;
        };
        RateLimiter::for('analytics-sync', static fn (Request $request): Limit => Limit::perMinute(20)->by($key($request, 'analytics-sync')));
        RateLimiter::for('copilot', static fn (Request $request): Limit => Limit::perMinute(10)->by($key($request, 'copilot')));
    }
}
