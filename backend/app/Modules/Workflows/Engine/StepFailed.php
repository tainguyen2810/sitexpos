<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use RuntimeException;

final class StepFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $requiresPaidRetry = false,
    ) {
        parent::__construct($message);
    }
}
