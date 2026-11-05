<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Domain error rendered with the standard error envelope
 * `{ "error": { "code", "message", "details", "request_id" } }`.
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message = '',
        public readonly array $details = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message, null, $headers);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', __('api.not_found'));
    }

    public static function forbidden(?string $code = null, ?string $message = null): self
    {
        return new self(403, $code ?? 'forbidden', $message ?? __('api.forbidden'));
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}
