<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Media\Contracts\MediaLibrary;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Policies\MediaPolicy;
use SiteXPost\Modules\Media\Queries\MediaLibraryQuery;

/**
 * Phase 6 — Media Engine (MEDIA-ENGINE.md).
 */
final class MediaServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        MediaAsset::class => MediaPolicy::class,
        ImageGenerationJob::class => MediaPolicy::class,
        ImagePlacement::class => MediaPolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(MediaLibrary::class, MediaLibraryQuery::class);
    }

    protected function bootModule(): void
    {
        $key = static function (Request $request, string $prefix): string {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return $prefix.':'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey;
        };
        RateLimiter::for('media-upload', static fn (Request $request): Limit => Limit::perMinute(30)->by($key($request, 'media-upload')));
        RateLimiter::for('media-ai', static fn (Request $request): Limit => Limit::perMinute(10)->by($key($request, 'media-ai')));
        RateLimiter::for('media-files', static fn (Request $request): Limit => Limit::perMinute(600)->by('media-files:'.$request->ip()));
    }
}
