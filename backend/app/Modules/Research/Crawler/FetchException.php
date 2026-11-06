<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Crawler;

use RuntimeException;

final class FetchException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
