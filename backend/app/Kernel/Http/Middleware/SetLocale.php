<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale priority: authenticated user's preference → Accept-Language → app default (vi).
 */
final class SetLocale
{
    public const SUPPORTED = ['vi', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $userLocale = $request->user()?->getAttribute('locale');
        $locale = is_string($userLocale) && in_array($userLocale, self::SUPPORTED, true)
            ? $userLocale
            : ($request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale'));

        app()->setLocale((string) $locale);

        return $next($request);
    }
}
