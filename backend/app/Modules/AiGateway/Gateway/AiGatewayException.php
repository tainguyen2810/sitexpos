<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use RuntimeException;

/**
 * Final outcome of a gateway call after retries and fallbacks.
 * `retryable` tells the workflow engine it may schedule another attempt later;
 * `requiresPaidRetry` means another attempt would be a new charge for a call that was already billed (invalid output) or may have been billed (timeout after send) — the engine then
 * retries only if the task route allows paid retries.
 */
final class AiGatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $requiresPaidRetry = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
