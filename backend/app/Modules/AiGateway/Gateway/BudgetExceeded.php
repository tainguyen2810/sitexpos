<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use RuntimeException;

final class BudgetExceeded extends RuntimeException
{
    public function __construct(public readonly string $scopeKey, public readonly string $period, public readonly int $limitMicroUsd)
    {
        parent::__construct("AI budget exceeded for {$scopeKey} ({$period}).");
    }
}
