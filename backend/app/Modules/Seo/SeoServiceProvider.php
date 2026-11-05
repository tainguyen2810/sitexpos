<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Seo\Domain\SeoLinkSuggestion;
use SiteXPost\Modules\Seo\Domain\SeoMetadata;
use SiteXPost\Modules\Seo\Policies\SeoPolicy;
use SiteXPost\Modules\Seo\Queries\SeoGateQuery;

/**
 * Phase 6 — SEO metadata, explainable audit, internal links, schema
 * (SEO-AUDIT-RULES.md).
 */
final class SeoServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        SeoMetadata::class => SeoPolicy::class,
        SeoAuditRun::class => SeoPolicy::class,
        SeoLinkSuggestion::class => SeoPolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(SeoGate::class, SeoGateQuery::class);
    }

    protected function bootModule(): void
    {
        RateLimiter::for('seo-audit', static function (Request $request): Limit {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return Limit::perMinute(30)->by('seo-audit:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey);
        });
    }
}
