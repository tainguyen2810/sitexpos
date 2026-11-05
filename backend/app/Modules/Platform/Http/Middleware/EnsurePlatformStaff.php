<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Platform\Enums\PlatformPermission;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Admin API: requires a platform role, a confirmed 2FA and,
 * optionally, a specific platform permission (`sitex.platform:platform.users.suspend`).
 * Non-staff receive 404 so the admin surface is not disclosed.
 */
final class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformStaff()) {
            throw ApiException::notFound();
        }

        if (! $user->hasConfirmedTwoFactor()) {
            throw ApiException::forbidden('two_factor_required', __('api.two_factor_required'));
        }

        if ($permission !== null && ! $user->hasPlatformPermission(PlatformPermission::from($permission))) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
