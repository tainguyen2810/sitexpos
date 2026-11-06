<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Contracts;

use RuntimeException;

final class UsageDenied extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
