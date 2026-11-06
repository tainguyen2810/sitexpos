<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Domain\PaymentReconciliation;
use SiteXPost\Modules\Billing\Domain\PaymentTransaction;
use SiteXPost\Modules\Billing\Domain\PaymentWebhookEvent;
use SiteXPost\Modules\Billing\Payments\Adapters\AdapterRegistry;
use SiteXPost\Modules\Billing\Support\BillingSettings;

/**
 * Transaction ingestion & matching (PAYMENT-RECONCILIATION.md).
 *
 * Auto-confirmation requires ALL of: verified source, exactly one valid
 * order reference, same currency, exact amount, order still payable, not a
 * duplicate transaction. Anything else lands in the manual review queue —
 * never auto-recorded.
 */
final class Reconciliation
{
    public const REFERENCE_PATTERN = '/SXP[A-HJ-NP-Z2-9]{8}/';

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly PaymentConfirmation $confirmation,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @return array{status: string, event_id?: string, results?: list<array{external_id: string, match_status: string, reason: string|null}>}
     */
    public function handleWebhook(string $providerCode, Request $request): array
    {
        $config = PaymentProviderConfig::query()->where('code', $providerCode)->where('is_enabled', true)->first()
            ?? throw ApiException::notFound();
        $adapter = $this->adapters->get($config->adapter);
        $hash = hash('sha256', (string) $request->getContent());
        try {
            $parsed = $adapter->parseWebhook($request, $config);
        } catch (WebhookRejected $e) {
            // Forged / replayed / malformed: logged without payload, nothing processed.
            Log::warning('Payment webhook rejected', ['provider' => $providerCode, 'reason' => $e->reason, 'payload_sha256' => $hash]);
            throw new ApiException(401, 'webhook_rejected', __('billing.errors.webhook_rejected'));
        }

        try {
            $event = new PaymentWebhookEvent;
            $event->forceFill([
                'provider' => $providerCode, 'event_id' => $parsed['event_id'], 'signature_valid' => true, 'payload_hash' => $hash,
                'payload' => ['transactions' => array_map(fn (NormalizedTransaction $t) => ['id' => $t->externalId, 'amount' => $t->amountMinor, 'currency' => $t->currency], $parsed['transactions'])],
                'status' => 'received', 'received_at' => now(),
            ])->save();
        } catch (QueryException) {
            return ['status' => 'duplicate', 'event_id' => $parsed['event_id']]; // replay of an already received event
        }

        $results = [];
        foreach ($parsed['transactions'] as $tx) {
            $t = $this->ingest($providerCode, $tx, $event->id, null);
            $results[] = ['external_id' => $tx->externalId, 'match_status' => $t->match_status, 'reason' => $t->match_reason];
        }
        $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();

        return ['status' => 'processed', 'event_id' => $parsed['event_id'], 'results' => $results];
    }

    /**
     * Store one normalized transaction (UNIQUE provider + external id) and
     * try to match it.
     */
    public function ingest(string $provider, NormalizedTransaction $tx, ?int $webhookEventId, ?string $recordedBy): PaymentTransaction
    {
        $existing = PaymentTransaction::query()->where('provider', $provider)->where('external_id', $tx->externalId)->first();
        if ($existing !== null) {
            $this->log($existing->id, $existing->payment_order_id, 'duplicate', 0, null, 'transaction already ingested');

            return $existing;
        }
        try {
            $t = new PaymentTransaction;
            $t->forceFill([
                'provider' => $provider, 'external_id' => $tx->externalId, 'direction' => $tx->direction, 'amount_minor' => $tx->amountMinor,
                'currency' => $tx->currency, 'description' => $tx->description, 'counterparty' => $tx->counterparty, 'occurred_at' => $tx->occurredAt,
                'match_status' => 'unmatched', 'webhook_event_id' => $webhookEventId, 'recorded_by' => $recordedBy,
            ])->save();
        } catch (QueryException) {
            return PaymentTransaction::query()->where('provider', $provider)->where('external_id', $tx->externalId)->firstOrFail();
        }

        $this->match($t);

        return $t->refresh();
    }

    public function match(PaymentTransaction $t): void
    {
        if ($t->direction !== 'in') {
            $this->mark($t, 'ignored', 'outgoing', null);

            return;
        }
        $refs = self::references((string) $t->description);
        if ($refs === []) {
            $this->mark($t, 'unmatched', 'reference_not_found', null);

            return;
        }
        if (count($refs) > 1) {
            $this->mark($t, 'unmatched', 'multiple_references', null);

            return;
        }
        // Platform-level lookup by unique reference (the order's tenant is then entered explicitly).
        $order = DB::table('payment_orders')->where('reference', $refs[0])->first(['id', 'workspace_id', 'status', 'amount_minor', 'currency', 'created_at', 'expires_at']);
        if ($order === null) {
            $this->mark($t, 'unmatched', 'reference_not_found', null);

            return;
        }
        $orderId = (string) $order->id;
        $diff = $t->amount_minor - (int) $order->amount_minor;
        $reason = match (true) {
            $t->currency !== $order->currency => 'currency_mismatch',
            in_array($order->status, ['PAID', 'REFUNDED', 'PARTIALLY_REFUNDED'], true) => 'order_already_paid',
            in_array($order->status, ['EXPIRED', 'CANCELLED', 'FAILED'], true) => 'order_not_payable',
            $t->occurred_at->lessThan(Carbon::parse((string) $order->created_at)->subMinutes(5)) => 'predates_order',
            $diff < 0 => 'underpaid',
            $diff > 0 && $this->settings->get('overpayment_policy') !== 'accept_and_credit_difference' => 'overpaid',
            default => null,
        };
        if ($reason !== null) {
            $this->mark($t, 'mismatch', $reason, $orderId, (string) $order->workspace_id, $diff);

            return;
        }

        try {
            $this->confirmation->confirm($orderId, $t->amount_minor, $t->id, 'auto', null, $diff > 0 ? 'overpaid by '.$diff : null);
            $this->log($t->id, $orderId, 'auto_matched', $diff, null, null);
        } catch (ApiException $e) {
            $this->mark($t, 'mismatch', $e->errorCode, $orderId, (string) $order->workspace_id, $diff);
        }
    }

    /**
     * Staff: approve a transaction for an order (also mismatched amounts,
     * with a mandatory note) — Manual Payment Review.
     */
    public function approve(PaymentTransaction $t, string $orderId, string $note, string $staffId): void
    {
        if ($t->match_status === 'matched') {
            throw ApiException::conflict('already_matched', __('billing.errors.already_matched'));
        }
        if ($t->direction !== 'in') {
            throw new ApiException(422, 'outgoing_transaction', __('billing.errors.outgoing_transaction'));
        }
        $order = DB::table('payment_orders')->where('id', $orderId)->first(['id', 'workspace_id', 'currency', 'amount_minor']) ?? throw ApiException::notFound();
        if ($order->currency !== $t->currency) {
            throw new ApiException(422, 'currency_mismatch', __('billing.errors.currency_mismatch'));
        }
        $this->confirmation->confirm($orderId, $t->amount_minor, $t->id, 'manual', $staffId, $note);
        $this->log($t->id, $orderId, 'manual_approved', $t->amount_minor - (int) $order->amount_minor, $staffId, $note);
        Audit::record('billing.transaction_approved', null, ['transaction' => $t->id, 'order' => $orderId, 'note' => $note], (string) $order->workspace_id, $staffId, 'staff');
    }

    public function reject(PaymentTransaction $t, string $note, string $staffId): void
    {
        if ($t->match_status === 'matched') {
            throw ApiException::conflict('already_matched', __('billing.errors.already_matched'));
        }
        $t->forceFill(['match_status' => 'ignored', 'match_reason' => 'rejected_by_staff'])->save();
        $this->log($t->id, $t->payment_order_id, 'manual_rejected', 0, $staffId, $note);
        Audit::record('billing.transaction_rejected', null, ['transaction' => $t->id, 'note' => $note], $t->workspace_id, $staffId, 'staff');
    }

    /**
     * Staff: a transfer verified in the bank statement without a feed
     * (manual bank transfer). The bank reference is unique per provider →
     * the same transfer can never confirm two orders.
     */
    public function manualPayment(string $orderId, string $bankReference, int $amountMinor, string $note, string $staffId): PaymentTransaction
    {
        $order = DB::table('payment_orders')->where('id', $orderId)->first(['id', 'workspace_id', 'currency', 'reference', 'status']) ?? throw ApiException::notFound();
        if (PaymentTransaction::query()->where('provider', 'manual')->where('external_id', $bankReference)->exists()) {
            throw ApiException::conflict('duplicate_transaction', __('billing.errors.duplicate_transaction'));
        }
        $t = new PaymentTransaction;
        $t->forceFill([
            'provider' => 'manual', 'external_id' => mb_substr($bankReference, 0, 120), 'direction' => 'in', 'amount_minor' => $amountMinor,
            'currency' => (string) $order->currency, 'description' => (string) $order->reference.' (manual)', 'occurred_at' => now(),
            'match_status' => 'unmatched', 'match_reason' => 'manual_entry', 'recorded_by' => $staffId,
        ])->save();
        $this->approve($t, $orderId, $note, $staffId);

        return $t->refresh();
    }

    /**
     * @return list<string>
     */
    public static function references(string $description): array
    {
        $norm = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $description) ?? '');
        preg_match_all(self::REFERENCE_PATTERN, $norm, $m);

        return array_values(array_unique($m[0]));
    }

    private function mark(PaymentTransaction $t, string $status, string $reason, ?string $orderId, ?string $workspaceId = null, int $diff = 0): void
    {
        $t->forceFill(['match_status' => $status, 'match_reason' => $reason, 'payment_order_id' => $orderId, 'workspace_id' => $workspaceId])->save();
        $result = match ($reason) {
            'underpaid', 'overpaid', 'currency_mismatch' => 'amount_mismatch',
            'order_not_payable' => 'order_expired',
            'order_already_paid' => 'duplicate',
            'outgoing' => 'ignored',
            default => 'reference_not_found',
        };
        $this->log($t->id, $orderId, $result, $diff, null, $reason);
    }

    private function log(?string $txId, ?string $orderId, string $result, int $diff, ?string $staffId, ?string $note): void
    {
        $r = new PaymentReconciliation;
        $r->forceFill([
            'payment_transaction_id' => $txId, 'payment_order_id' => $orderId, 'result' => $result, 'difference_minor' => $diff,
            'reviewed_by' => $staffId, 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'created_at' => now(),
        ])->save();
    }
}
