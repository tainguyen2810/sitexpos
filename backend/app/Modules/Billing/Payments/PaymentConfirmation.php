<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Accounting\Documents;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\RevenueRecord;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionManager;
use SiteXPost\Modules\Billing\Support\BillingNotifier;
use SiteXPost\Modules\Billing\Support\InTenant;

/**
 * The ONLY place a payment becomes PAID (PAYMENT-RECONCILIATION.md §Confirm):
 * mark paid → fulfil (subscription / credits, ledger) → entitlements follow
 * → revenue record → receipt → notification → audit, in ONE transaction
 * holding the order row lock. `fulfilled_at` + unique source keys make it
 * idempotent: a replayed webhook or a double click can never activate twice
 * or add credits twice.
 */
final class PaymentConfirmation
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly CreditLedger $credits,
        private readonly Documents $documents,
        private readonly BillingNotifier $notifier,
    ) {}

    /**
     * @return bool true when this call fulfilled the order, false when it was already fulfilled
     */
    public function confirm(string $orderId, int $paidAmountMinor, ?string $transactionId, string $source, ?string $actorId, ?string $note = null): bool
    {
        $row = DB::table('payment_orders')->where('id', $orderId)->first(['workspace_id']) ?? throw ApiException::notFound();

        $fulfilled = InTenant::run((string) $row->workspace_id, fn (): bool => DB::transaction(function () use ($orderId, $paidAmountMinor, $transactionId, $source, $actorId, $note): bool {
            $order = PaymentOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->fulfilled_at !== null || in_array($order->status, ['PAID', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                return false;
            }
            if (! in_array('PAID', PaymentOrders::TRANSITIONS[$order->status] ?? [], true)) {
                throw ApiException::conflict('invalid_order_transition', __('billing.errors.invalid_order_transition', ['from' => $order->status, 'to' => 'PAID']));
            }
            // A manual approval of an EXPIRED / FAILED order is allowed (late transfer verified by staff).
            if (in_array($order->status, ['EXPIRED', 'FAILED'], true) && $source !== 'manual') {
                throw ApiException::conflict('order_expired', __('billing.errors.order_expired'));
            }

            $order->forceFill(['status' => 'PAID', 'paid_amount_minor' => $paidAmountMinor, 'paid_at' => now()])->save();

            $serviceStart = null;
            $serviceEnd = null;
            $grantId = null;
            $planId = null;
            if (in_array($order->purpose, ['subscription', 'renewal', 'upgrade'], true)) {
                $sub = $this->subscriptions->fulfil($order);
                $order->forceFill(['subscription_id' => $sub->id])->save();
                $serviceStart = $order->purpose === 'upgrade' ? now() : $sub->current_period_start;
                $serviceEnd = $sub->current_period_end;
                $planId = $sub->plan_id;
            } elseif ($order->purpose === 'credit_package') {
                $pkg = CreditPackage::query()->find($order->credit_package_id);
                $snap = is_array($order->snapshot) ? $order->snapshot : [];
                $credits = (int) ($snap['credits_mc'] ?? ($pkg !== null ? $pkg->credits_mc : 0));
                $bonus = (int) ($snap['bonus_credits_mc'] ?? ($pkg !== null ? $pkg->bonus_credits_mc : 0));
                $days = $snap['validity_days'] ?? $pkg?->validity_days;
                $expires = $days !== null ? now()->addDays((int) $days) : null;
                $grant = $this->credits->grant($order->workspace_id, 'purchased', $credits, $expires, 'order:'.$order->id, (int) $order->amount_minor, $order->currency, 'credit package '.($snap['code'] ?? ''), $actorId, 'payment_order', $order->id);
                $grantId = $grant->id;
                if ($bonus > 0) {
                    $this->credits->grant($order->workspace_id, 'bonus', $bonus, $expires, 'order:'.$order->id.':bonus', 0, $order->currency, 'bonus credits', $actorId, 'payment_order', $order->id);
                }
            }

            $rev = new RevenueRecord;
            $rev->forceFill([
                'kind' => $order->purpose === 'credit_package' ? 'credits' : 'subscription', 'source_key' => 'order:'.$order->id,
                'payment_order_id' => $order->id, 'plan_id' => $planId, 'amount_minor' => $paidAmountMinor, 'discount_minor' => 0,
                'currency' => $order->currency, 'service_start' => $serviceStart, 'service_end' => $serviceEnd,
                'credit_grant_id' => $grantId, 'occurred_at' => now(), 'created_at' => now(),
            ])->save();

            $this->documents->receipt($order);
            $order->forceFill(['fulfilled_at' => now()])->save();
            if ($transactionId !== null) {
                DB::table('payment_transactions')->where('id', $transactionId)->update([
                    'match_status' => 'matched', 'payment_order_id' => $order->id, 'workspace_id' => $order->workspace_id, 'updated_at' => now(),
                ]);
            }
            Audit::record('billing.payment_confirmed', $order, [
                'source' => $source, 'amount_minor' => $paidAmountMinor, 'currency' => $order->currency, 'transaction' => $transactionId, 'note' => $note,
            ], $order->workspace_id, $actorId, $actorId !== null ? 'staff' : 'system');
            $this->notifier->send($order->workspace_id, 'payment_succeeded', 'paid:'.$order->id, ['reference' => $order->reference]);

            return true;
        }));

        return $fulfilled;
    }
}
