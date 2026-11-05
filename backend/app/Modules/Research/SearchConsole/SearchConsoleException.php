<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\SearchConsole;

use RuntimeException;

final class SearchConsoleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
