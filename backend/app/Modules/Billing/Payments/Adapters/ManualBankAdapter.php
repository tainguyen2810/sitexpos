<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments\Adapters;

use Illuminate\Http\Request;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Payments\VietQr;
use SiteXPost\Modules\Billing\Payments\WebhookRejected;

/**
 * Vietnamese bank transfer with a VietQR code. No automatic confirmation:
 * a staff member verifies the incoming transfer (Manual Payment Review), or
 * an authorized transaction feed (`signed_webhook`) reports it. Internet
 * banking scraping / customer bank passwords are never used.
 */
final class ManualBankAdapter implements PaymentAdapter
{
    public function key(): string
    {
        return 'manual_bank';
    }

    public function capabilities(): array
    {
        return ['webhook' => false, 'refund_api' => false, 'qr' => true, 'manual_review' => true];
    }

    public function requiredConfig(): array
    {
        return ['settings' => ['bank_bin', 'account_no', 'account_name'], 'credentials' => []];
    }

    public function instructions(PaymentOrder $order, PaymentProviderConfig $config): array
    {
        $bin = (string) $config->setting('bank_bin', '');
        $account = (string) $config->setting('account_no', '');
        $ready = $bin !== '' && $account !== '' && $order->currency === 'VND';

        return [
            'type' => 'bank_transfer',
            'configured' => $ready,
            'bank_name' => $config->setting('bank_name'),
            'bank_bin' => $bin !== '' ? $bin : null,
            'account_no' => $account !== '' ? $account : null,
            'account_name' => $config->setting('account_name'),
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'memo' => $order->reference,
            'qr_payload' => $ready ? VietQr::payload($bin, $account, $order->amount_minor, $order->reference) : null,
            'confirmation' => 'manual_review',
        ];
    }

    public function parseWebhook(Request $request, PaymentProviderConfig $config): array
    {
        throw new WebhookRejected('webhook_not_supported');
    }
}
