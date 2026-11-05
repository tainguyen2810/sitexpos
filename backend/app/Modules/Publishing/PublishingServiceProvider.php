<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Publishing\Console\DispatchPublishing;
use SiteXPost\Modules\Publishing\Console\RunAutoPublishing;
use SiteXPost\Modules\Publishing\Domain\PublishingBatch;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Policies\PublishingPolicyGate;

/**
 * Phase 6 — WordPress Publisher (WORDPRESS-PUBLISHER.md, PUBLISHING-RECOVERY.md).
 */
final class PublishingServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        PublishingJob::class => PublishingPolicyGate::class,
        PublishingBatch::class => PublishingPolicyGate::class,
        PublishingConflict::class => PublishingPolicyGate::class,
        WordPressPostMapping::class => PublishingPolicyGate::class,
    ];

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchPublishing::class, RunAutoPublishing::class]);
        }
        RateLimiter::for('publishing', static function (Request $request): Limit {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return Limit::perMinute(60)->by('publishing:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey);
        });
    }
}
