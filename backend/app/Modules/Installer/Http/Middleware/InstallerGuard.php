<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Installer\Support\InstallationState;
use SiteXPost\Modules\Installer\Support\SetupToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the installer (INSTALLER-SECURITY.md):
 *  - disabled by config → 404;
 *  - already installed (any of several signals) → page shows "installed",
 *    API answers 403 installer_locked, nothing can be re-run;
 *  - API calls require the one-time setup token in X-Setup-Token (no cookies
 *    are used, so cross-site requests cannot carry it: CSRF does not apply);
 *  - rate limits on a file-based store (works before DB/Redis exist).
 */
final class InstallerGuard
{
    public const HEADER = 'X-Setup-Token';

    public function __construct(private readonly InstallationState $state, private readonly SetupToken $token) {}

    public function handle(Request $request, Closure $next, string $mode = 'api'): Response
    {
        if (! (bool) config('sitex.installer.enabled', true)) {
            throw ApiException::notFound();
        }

        $limiter = new RateLimiter(Cache::store((string) config('sitex.installer.cache_store', 'file')));
        $ip = (string) $request->ip();

        if ($limiter->tooManyAttempts('installer:req:'.$ip, 120)) {
            throw new ApiException(429, 'rate_limited', __('api.rate_limited'), [], ['Retry-After' => (string) $limiter->availableIn('installer:req:'.$ip)]);
        }
        $limiter->hit('installer:req:'.$ip, 60);

        if ($mode === 'page') {
            return $next($request);
        }

        if ($this->state->isInstalled()) {
            throw ApiException::forbidden('installer_locked', __('installer.locked'));
        }

        $failKey = 'installer:token-fail:'.$ip;
        $globalKey = 'installer:token-fail:global';
        if ($limiter->tooManyAttempts($failKey, 10) || $limiter->tooManyAttempts($globalKey, 50)) {
            $retry = max($limiter->availableIn($failKey), $limiter->availableIn($globalKey));
            throw new ApiException(429, 'rate_limited', __('installer.too_many_attempts'), [], ['Retry-After' => (string) $retry]);
        }

        if (! $this->token->verify($request->header(self::HEADER))) {
            $limiter->hit($failKey, 600);
            $limiter->hit($globalKey, 3600);
            throw new ApiException(401, 'invalid_setup_token', __('installer.invalid_token'));
        }

        return $next($request);
    }
}
