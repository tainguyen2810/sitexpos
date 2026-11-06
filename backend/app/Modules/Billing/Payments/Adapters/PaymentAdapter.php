<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments\Adapters;

use Illuminate\Http\Request;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Payments\NormalizedTransaction;
use SiteXPost\Modules\Billing\Payments\WebhookRejected;

/**
 * Payment provider adapter (PAYMENT-RECONCILIATION.md §Adapters). New
 * providers (bank transaction APIs, card/e-wallet gateways) implement this
 * interface; nothing else in Billing knows a provider by name.
 */
interface PaymentAdapter
{
    public function key(): string;

    /**
     * @return array{webhook: bool, refund_api: bool, qr: bool, manual_review: bool}
     */
    public function capabilities(): array;

    /**
     * Keys required in settings / credentials for the provider to be usable.
     *
     * @return array{settings: list<string>, credentials: list<string>}
     */
    public function requiredConfig(): array;

    /**
     * Customer-facing payment instructions (bank account, QR payload, memo).
     *
     * @return array<string, mixed>
     */
    public function instructions(PaymentOrder $order, PaymentProviderConfig $config): array;

    /**
     * Verify authenticity (signature, timestamp window) and normalize.
     *
     * @return array{event_id: string, transactions: list<NormalizedTransaction>}
     *
     * @throws WebhookRejected
     */
    public function parseWebhook(Request $request, PaymentProviderConfig $config): array;
}
