<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Security;

use RuntimeException;

final class SsrfViolation extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Outbound URL rejected: {$reason}");
    }
}
