<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

use RuntimeException;

final class WebhookRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
