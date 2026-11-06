<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments\Adapters;

use SiteXPost\Kernel\Http\ApiException;

final class AdapterRegistry
{
    /** @var array<string, class-string<PaymentAdapter>> */
    public const ADAPTERS = [
        'manual_bank' => ManualBankAdapter::class,
        'signed_webhook' => SignedWebhookAdapter::class,
    ];

    public function get(string $adapter): PaymentAdapter
    {
        $class = self::ADAPTERS[$adapter] ?? throw new ApiException(422, 'unknown_adapter', __('billing.errors.unknown_adapter'));

        return app($class);
    }
}
