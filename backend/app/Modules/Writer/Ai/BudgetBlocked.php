<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Ai;

use RuntimeException;

/**
 * Raised BEFORE a paid call when the article's hard budget cap would be
 * exceeded. Nothing is sent to the provider; completed work is kept.
 */
final class BudgetBlocked extends RuntimeException
{
    public function __construct(public readonly int $needed, public readonly int $remaining, public readonly string $scope = 'article')
    {
        parent::__construct("Budget exhausted ({$scope}): needs ~{$needed} micro-USD, {$remaining} left.");
    }
}
