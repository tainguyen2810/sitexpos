<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Identity\Domain\User;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            throw ApiException::forbidden('email_not_verified', __('api.email_not_verified'));
        }

        return $next($request);
    }
}
