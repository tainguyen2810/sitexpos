<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments\Adapters;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Payments\NormalizedTransaction;
use SiteXPost\Modules\Billing\Payments\WebhookRejected;

/**
 * Generic adapter for an AUTHORIZED bank-transaction feed / payment provider
 * that pushes signed webhooks (no specific vendor hardcoded).
 *
 * Request: header `X-SiteX-Timestamp: <unix seconds>`, header
 * `X-SiteX-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + raw body)>`,
 * JSON body `{"event_id": "...", "transactions": [{"id", "amount", "currency",
 * "description", "occurred_at", "direction", "counterparty"}]}`.
 * Rejected: bad/missing signature, timestamp outside the tolerance window
 * (replay), malformed body. Duplicate event ids are dropped by the ingestor.
 * A vendor-specific provider gets its own adapter mapping its format here.
 */
final class SignedWebhookAdapter implements PaymentAdapter
{
    public function key(): string
    {
        return 'signed_webhook';
    }

    public function capabilities(): array
    {
        return ['webhook' => true, 'refund_api' => false, 'qr' => true, 'manual_review' => true];
    }

    public function requiredConfig(): array
    {
        return ['settings' => ['bank_bin', 'account_no', 'account_name'], 'credentials' => ['webhook_secret']];
    }

    public function instructions(PaymentOrder $order, PaymentProviderConfig $config): array
    {
        $out = (new ManualBankAdapter)->instructions($order, $config);
        $out['confirmation'] = 'automatic';

        return $out;
    }

    public function parseWebhook(Request $request, PaymentProviderConfig $config): array
    {
        $secret = $config->secret('webhook_secret') ?? throw new WebhookRejected('provider_not_configured');
        $ts = (string) $request->header('X-SiteX-Timestamp', '');
        $sig = (string) $request->header('X-SiteX-Signature', '');
        if ($ts === '' || ! ctype_digit($ts) || ! str_starts_with($sig, 'sha256=')) {
            throw new WebhookRejected('missing_signature');
        }
        $tolerance = max(30, (int) $config->setting('timestamp_tolerance_seconds', 300));
        if (abs(time() - (int) $ts) > $tolerance) {
            throw new WebhookRejected('timestamp_out_of_window');
        }
        $raw = (string) $request->getContent();
        $expected = hash_hmac('sha256', $ts.'.'.$raw, $secret);
        if (! hash_equals($expected, substr($sig, 7))) {
            throw new WebhookRejected('invalid_signature');
        }
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_string($body['event_id'] ?? null) || $body['event_id'] === '' || ! is_array($body['transactions'] ?? null)) {
            throw new WebhookRejected('malformed_payload');
        }
        $txs = [];
        foreach (array_slice($body['transactions'], 0, 100) as $t) {
            if (! is_array($t) || ! isset($t['id'], $t['amount']) || ! is_numeric($t['amount']) || (string) $t['id'] === '') {
                throw new WebhookRejected('malformed_transaction');
            }
            if ((float) $t['amount'] != (int) $t['amount'] || (int) $t['amount'] < 0) {
                throw new WebhookRejected('invalid_amount'); // integer minor units only
            }
            try {
                $at = isset($t['occurred_at']) ? CarbonImmutable::parse((string) $t['occurred_at'])->utc() : CarbonImmutable::now();
            } catch (\Throwable) {
                throw new WebhookRejected('invalid_timestamp');
            }
            $txs[] = new NormalizedTransaction(
                externalId: mb_substr((string) $t['id'], 0, 120),
                amountMinor: (int) $t['amount'],
                currency: strtoupper(mb_substr((string) ($t['currency'] ?? 'VND'), 0, 3)),
                description: isset($t['description']) ? mb_substr((string) $t['description'], 0, 500) : null,
                occurredAt: $at,
                direction: ($t['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                counterparty: isset($t['counterparty']) ? mb_substr((string) $t['counterparty'], 0, 191) : null,
            );
        }

        return ['event_id' => mb_substr($body['event_id'], 0, 120), 'transactions' => $txs];
    }

    /** Test/support helper: sign a body the way the provider must. */
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
