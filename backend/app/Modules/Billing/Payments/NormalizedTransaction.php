<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

use Carbon\CarbonImmutable;

final readonly class NormalizedTransaction
{
    public function __construct(
        public string $externalId,
        public int $amountMinor,
        public string $currency,
        public ?string $description,
        public CarbonImmutable $occurredAt,
        public string $direction = 'in',
        public ?string $counterparty = null,
    ) {}
}
