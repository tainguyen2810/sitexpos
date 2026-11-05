<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Converts every exception on API routes into the documented error envelope.
 * Never leaks stack traces or internal messages (unless APP_DEBUG=true).
 */
final class ApiExceptionRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        402 => 'payment_required',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'csrf_mismatch',
        422 => 'validation_failed',
        429 => 'rate_limited',
        503 => 'maintenance',
    ];

    public static function shouldRender(Request $request): bool
    {
        return $request->is('api/*') || $request->is('connector/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldRender($request)) {
            return null;
        }

        [$status, $code, $message, $details, $headers] = self::describe($e);

        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $request->attributes->get('request_id'),
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        if ($status >= 500 && config('app.debug')) {
            $payload['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse(['error' => $payload], $status, $headers);
    }

    /**
     * @return array{0:int,1:string,2:string,3:array<string,mixed>,4:array<string,string>}
     */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return [$e->getStatusCode(), $e->errorCode, $e->getMessage() ?: __('api.error'), $e->details, self::headers($e)];
        }

        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', __('api.validation_failed'), $e->errors(), []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', __('api.unauthenticated'), [], []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'error');
            $message = match ($status) {
                403 => __('api.forbidden'),
                404 => __('api.not_found'),
                419 => __('api.csrf_mismatch'),
                429 => __('api.rate_limited'),
                503 => __('api.maintenance'),
                default => $status >= 500 ? __('api.server_error') : ($e->getMessage() ?: __('api.error')),
            };

            return [$status, $code, $message, [], self::headers($e)];
        }

        return [500, 'server_error', __('api.server_error'), [], []];
    }

    /**
     * @return array<string, string>
     */
    private static function headers(HttpExceptionInterface $e): array
    {
        return array_map(static fn ($v): string => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders());
    }
}
