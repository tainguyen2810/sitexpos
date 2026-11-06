<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Accounting\Documents;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\CreditGrant;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\Refund;
use SiteXPost\Modules\Billing\Domain\RevenueRecord;
use SiteXPost\Modules\Billing\Support\BillingNotifier;
use SiteXPost\Modules\Billing\Support\InTenant;

/**
 * Refunds (staff only, `platform.billing.manage`, audited). No provider in
 * use has a refund API, so a refund is: request → staff transfers the money
 * back → staff records the outcome. Credits bought with the order are
 * reversed proportionally (only the unused remainder). Subscription access is
 * not changed implicitly — staff decides (cancel / suspend) separately.
 */
final class Refunds
{
    public function __construct(
        private readonly CreditLedger $credits,
        private readonly Documents $documents,
        private readonly PaymentOrders $orders,
        private readonly BillingNotifier $notifier,
    ) {}

    public function request(PaymentOrder $order, int $amountMinor, string $reason, string $staffId): Refund
    {
        return InTenant::run($order->workspace_id, fn (): Refund => DB::transaction(function () use ($order, $amountMinor, $reason, $staffId): Refund {
            $o = PaymentOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($o->status, ['PAID', 'PARTIALLY_REFUNDED'], true)) {
                throw ApiException::conflict('order_not_refundable', __('billing.errors.order_not_refundable'));
            }
            $open = (int) Refund::query()->where('payment_order_id', $o->id)->whereIn('status', ['requested', 'processing'])->sum('amount_minor');
            $refundable = $o->paid_amount_minor - $o->refunded_amount_minor - $open;
            if ($amountMinor <= 0 || $amountMinor > $refundable) {
                throw new ApiException(422, 'refund_amount_invalid', __('billing.errors.refund_amount_invalid', ['max' => $refundable]), ['max_minor' => $refundable]);
            }
            $r = new Refund;
            $r->forceFill([
                'payment_order_id' => $o->id, 'amount_minor' => $amountMinor, 'currency' => $o->currency, 'status' => 'requested',
                'reason' => mb_substr($reason, 0, 500), 'requested_by' => $staffId,
            ])->save();
            Audit::record('billing.refund_requested', $r, ['order' => $o->id, 'amount_minor' => $amountMinor, 'reason' => $reason], $o->workspace_id, $staffId, 'staff');

            return $r;
        }));
    }

    /**
     * Record the outcome. Success: order refunded amount, credit reversal,
     * negative revenue, refund record, notification. Failure: nothing moves.
     */
    public function complete(Refund $refund, bool $succeeded, ?string $externalRef, ?string $failureReason, string $staffId): Refund
    {
        return InTenant::run($refund->workspace_id, fn (): Refund => DB::transaction(function () use ($refund, $succeeded, $externalRef, $failureReason, $staffId): Refund {
            $r = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->status, ['requested', 'processing'], true)) {
                throw ApiException::conflict('refund_already_processed', __('billing.errors.refund_already_processed'));
            }
            if (! $succeeded) {
                $r->forceFill(['status' => 'failed', 'failure_reason' => mb_substr((string) $failureReason, 0, 255), 'processed_by' => $staffId, 'processed_at' => now()])->save();
                Audit::record('billing.refund_failed', $r, ['reason' => $failureReason], $r->workspace_id, $staffId, 'staff');

                return $r;
            }
            $o = PaymentOrder::query()->whereKey($r->payment_order_id)->lockForUpdate()->firstOrFail();
            $reversed = 0;
            if ($o->purpose === 'credit_package') {
                $grant = CreditGrant::query()->where('source_ref', 'order:'.$o->id)->first();
                if ($grant !== null && $o->paid_amount_minor > 0) {
                    $share = intdiv($grant->amount_mc * $r->amount_minor + $o->paid_amount_minor - 1, $o->paid_amount_minor);
                    $reversed = $this->credits->reverseGrant($o->workspace_id, $grant->id, $share, 'refund:'.$r->id, 'refund '.$o->reference, $staffId);
                }
            }
            $refunded = $o->refunded_amount_minor + $r->amount_minor;
            $this->orders->transition($o, $refunded >= $o->paid_amount_minor ? 'REFUNDED' : 'PARTIALLY_REFUNDED', ['refunded_amount_minor' => $refunded]);

            $r->forceFill(['status' => 'succeeded', 'external_ref' => $externalRef, 'credits_reversed_mc' => $reversed, 'processed_by' => $staffId, 'processed_at' => now()])->save();

            $rev = new RevenueRecord;
            $rev->forceFill([
                'kind' => 'refund', 'source_key' => 'refund:'.$r->id, 'payment_order_id' => $o->id, 'refund_id' => $r->id,
                'amount_minor' => -$r->amount_minor, 'discount_minor' => 0, 'currency' => $r->currency, 'occurred_at' => now(), 'created_at' => now(),
            ])->save();
            $this->documents->refundRecord($r, $o);
            Audit::record('billing.refund_succeeded', $r, ['order' => $o->id, 'amount_minor' => $r->amount_minor, 'credits_reversed_mc' => $reversed], $r->workspace_id, $staffId, 'staff');
            $this->notifier->send($r->workspace_id, 'refund_processed', 'refund:'.$r->id, ['reference' => $o->reference]);

            return $r;
        }));
    }
}
