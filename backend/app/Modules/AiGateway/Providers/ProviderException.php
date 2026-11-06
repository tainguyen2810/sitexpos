<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use RuntimeException;

/**
 * A failed provider call, classified for the retry policy:
 * - retryable: the same call may succeed later (429, 5xx, connect failure)
 * - billingUncertain: the request may have reached the provider and been
 *   billed (response timeout, dropped connection after send). Such calls are
 *   NEVER retried automatically unless the task route allows paid retries.
 * - countsAsFailure: counts towards the provider circuit breaker
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $billingUncertain = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $countsAsFailure = false,
        public readonly ?Usage $usage = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $provider): self
    {
        return new self('provider_not_configured', "Provider {$provider} has no API key configured.");
    }

    /**
     * Map an HTTP error status to a classified exception.
     */
    public static function fromStatus(int $status, string $message, ?int $retryAfter = null): self
    {
        return match (true) {
            $status === 401, $status === 403 => new self('auth_failed', $message, httpStatus: $status, countsAsFailure: true),
            $status === 404 => new self('model_not_found', $message, httpStatus: $status),
            $status === 408 => new self('provider_timeout', $message, retryable: true, httpStatus: $status, retryAfter: $retryAfter, countsAsFailure: true),
            $status === 413 => new self('request_too_large', $message, httpStatus: $status),
            $status === 429 => new self('rate_limited', $message, retryable: true, httpStatus: $status, retryAfter: $retryAfter),
            $status === 400, $status === 422 => new self('bad_request', $message, httpStatus: $status),
            $status >= 500 => new self('provider_unavailable', $message, retryable: true, httpStatus: $status, retryAfter: $retryAfter, countsAsFailure: true),
            default => new self('provider_error', $message, httpStatus: $status),
        };
    }
}
