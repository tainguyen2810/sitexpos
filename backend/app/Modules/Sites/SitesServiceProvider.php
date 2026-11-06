<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Sites\Connector\WordPressGatewayService;
use SiteXPost\Modules\Sites\Console\PruneSiteData;
use SiteXPost\Modules\Sites\Console\ScheduleHealthChecks;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Policies\SitePolicy;
use SiteXPost\Modules\Sites\Queries\SiteDirectoryQuery;

final class SitesServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        Site::class => SitePolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(SiteDirectory::class, SiteDirectoryQuery::class);
        $this->app->bind(WordPressGateway::class, WordPressGatewayService::class);
    }

    protected function bootModule(): void
    {
        RateLimiter::for('connector-pairing', static fn (Request $request): Limit => Limit::perHour((int) config('sitex.connector.pairing_per_hour_ip', 10))
            ->by('pair:'.$request->ip()));

        RateLimiter::for('plugin-download', static fn (Request $request): Limit => Limit::perMinute((int) config('sitex.plugin.downloads_per_minute_ip', 20))
            ->by('plugin:'.$request->ip()));

        RateLimiter::for('connector-site', static fn (Request $request): Limit => Limit::perMinute((int) config('sitex.connector.requests_per_minute_site', 60))
            ->by('connector:'.substr((string) $request->header('X-SiteX-Key-Id', (string) $request->ip()), 0, 40)));

        if ($this->app->runningInConsole()) {
            $this->commands([ScheduleHealthChecks::class, PruneSiteData::class]);
        }
    }
}
