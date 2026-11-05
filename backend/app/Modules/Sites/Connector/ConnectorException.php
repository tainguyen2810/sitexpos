<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Connector;

use RuntimeException;

/**
 * Failure talking to a WordPress site. `errorCode` is a stable machine code,
 * the message is safe to show to customers (no secrets, no raw bodies).
 */
final class ConnectorException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
