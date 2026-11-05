<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Identity\Domain\User;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            throw ApiException::forbidden('account_suspended', __('api.account_suspended'));
        }

        return $next($request);
    }
}
