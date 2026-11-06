<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Credits;

use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $requiredMc, public readonly int $availableMc)
    {
        parent::__construct('Not enough AI credits.');
    }
}
