<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connector endpoints only accept small JSON bodies.
 */
final class LimitConnectorBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > 64 * 1024) {
            throw new ApiException(413, 'payload_too_large', __('api.validation_failed'));
        }

        return $next($request);
    }
}
