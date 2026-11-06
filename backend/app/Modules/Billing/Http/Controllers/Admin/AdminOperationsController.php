<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Credits\InsufficientCredits;
use SiteXPost\Modules\Billing\Domain\CreditAdjustment;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\PaymentTransaction;
use SiteXPost\Modules\Billing\Domain\Refund;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\SubscriptionEvent;
use SiteXPost\Modules\Billing\Http\BillingPresenter;
use SiteXPost\Modules\Billing\Http\Requests\Admin\CompleteRefundRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\CreditAdjustmentRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\ManualPaymentRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\RefundRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\ReviewTransactionRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\SubscriptionActionRequest;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Billing\Payments\Reconciliation;
use SiteXPost\Modules\Billing\Payments\Refunds;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionManager;
use SiteXPost\Modules\Billing\Support\AdminLookup;
use SiteXPost\Modules\Billing\Support\InTenant;

/**
 * Super Admin billing operations: subscriptions, payment orders,
 * transaction reconciliation & manual review, refunds, credit adjustments,
 * uncertain AI usage. Lists are platform-level read-only queries; every
 * mutation runs inside the tenant's context and is audited.
 */
final class AdminOperationsController
{
    public function subscriptions(Request $request): JsonResponse
    {
        $q = DB::table('subscriptions')->join('workspaces', 'workspaces.id', '=', 'subscriptions.workspace_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select('subscriptions.*', 'workspaces.name as workspace_name', 'plans.code as plan_code', 'plans.name as plan_name')
            ->orderByDesc('subscriptions.created_at')->orderByDesc('subscriptions.id');
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $q->where('subscriptions.status', $status);
        }
        if ($request->boolean('live')) {
            $q->whereNotNull('subscriptions.live_key');
        }
        $ws = $request->query('workspace_id');
        if (is_string($ws) && Str::isUlid($ws)) {
            $q->where('subscriptions.workspace_id', $ws);
        }

        return BillingPresenter::rows($q, fn (object $r) => [
            'id' => $r->id, 'workspace_id' => $r->workspace_id, 'workspace_name' => $r->workspace_name, 'plan_code' => $r->plan_code,
            'plan_name' => $r->plan_name, 'status' => $r->status, 'interval' => $r->interval, 'price_minor' => (int) $r->price_minor,
            'currency' => $r->currency, 'current_period_end' => BillingPresenter::ts($r->current_period_end),
            'trial_ends_at' => BillingPresenter::ts($r->trial_ends_at), 'grace_ends_at' => BillingPresenter::ts($r->grace_ends_at),
            'is_live' => $r->live_key !== null, 'created_at' => BillingPresenter::ts($r->created_at),
        ], $request, 50);
    }

    public function subscription(string $subscription): JsonResponse
    {
        $s = AdminLookup::find(Subscription::class, $subscription);
        $events = InTenant::run($s->workspace_id, fn () => SubscriptionEvent::query()->where('subscription_id', $s->id)->orderByDesc('id')->limit(100)->get());

        return new JsonResponse(['data' => BillingPresenter::subscription($s) + ['events' => $events->map(fn ($e) => BillingPresenter::event($e))]]);
    }

    public function subscriptionAction(SubscriptionActionRequest $request, string $subscription, SubscriptionManager $manager): JsonResponse
    {
        $s = AdminLookup::find(Subscription::class, $subscription);
        $d = $request->validated();
        $staff = $this->staff($request);
        $s = match ($d['action']) {
            'cancel' => $manager->cancel($s, $staff, $d['reason'], 'staff'),
            default => $manager->staffAction($s, $d['action'], $d['reason'], isset($d['days']) ? (int) $d['days'] : null, $staff),
        };

        return new JsonResponse(['data' => BillingPresenter::subscription($s)]);
    }

    public function orders(Request $request): JsonResponse
    {
        $q = DB::table('payment_orders')->join('workspaces', 'workspaces.id', '=', 'payment_orders.workspace_id')
            ->select('payment_orders.*', 'workspaces.name as workspace_name')->orderByDesc('payment_orders.created_at')->orderByDesc('payment_orders.id');
        foreach (['status', 'purpose', 'provider'] as $f) {
            $v = $request->query($f);
            if (is_string($v) && $v !== '') {
                $q->where('payment_orders.'.$f, $v);
            }
        }
        $ref = $request->query('reference');
        if (is_string($ref) && $ref !== '') {
            $q->where('payment_orders.reference', strtoupper(mb_substr($ref, 0, 24)));
        }

        return BillingPresenter::rows($q, fn (object $o) => [
            'id' => $o->id, 'workspace_id' => $o->workspace_id, 'workspace_name' => $o->workspace_name, 'purpose' => $o->purpose,
            'description' => $o->description, 'amount_minor' => (int) $o->amount_minor, 'currency' => $o->currency, 'provider' => $o->provider,
            'reference' => $o->reference, 'status' => $o->status, 'paid_amount_minor' => (int) $o->paid_amount_minor,
            'refunded_amount_minor' => (int) $o->refunded_amount_minor, 'expires_at' => BillingPresenter::ts($o->expires_at),
            'paid_at' => BillingPresenter::ts($o->paid_at), 'created_at' => BillingPresenter::ts($o->created_at),
        ], $request, 50);
    }

    public function order(string $order): JsonResponse
    {
        $o = AdminLookup::find(PaymentOrder::class, $order);
        $refunds = InTenant::run($o->workspace_id, fn () => Refund::query()->where('payment_order_id', $o->id)->orderByDesc('created_at')->get());

        return new JsonResponse(['data' => BillingPresenter::order($o) + [
            'refunds' => $refunds->map(fn ($r) => BillingPresenter::refund($r)),
            'transactions' => PaymentTransaction::query()->where('payment_order_id', $o->id)->get()->map(fn ($t) => self::tx($t)),
            'reconciliations' => DB::table('payment_reconciliations')->where('payment_order_id', $o->id)->orderByDesc('id')->limit(50)->get(),
        ]]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $q = PaymentTransaction::query()->orderByDesc('occurred_at')->orderByDesc('id');
        $status = $request->query('match_status');
        if (is_string($status) && $status !== '') {
            $q->where('match_status', $status);
        } elseif ($request->boolean('needs_review')) {
            $q->whereIn('match_status', ['unmatched', 'mismatch']);
        }

        return BillingPresenter::page($q, fn (PaymentTransaction $t) => self::tx($t), $request, 50);
    }

    public function approveTransaction(ReviewTransactionRequest $request, string $transaction, Reconciliation $reconciliation): JsonResponse
    {
        $t = PaymentTransaction::query()->findOrFail($transaction);
        $orderId = (string) $request->validated('order_id');
        if ($orderId === '') {
            throw new ApiException(422, 'order_required', __('billing.errors.order_required'));
        }
        $reconciliation->approve($t, $orderId, (string) $request->validated('note'), $this->staff($request));

        return new JsonResponse(['data' => self::tx($t->refresh())]);
    }

    public function rejectTransaction(ReviewTransactionRequest $request, string $transaction, Reconciliation $reconciliation): JsonResponse
    {
        $t = PaymentTransaction::query()->findOrFail($transaction);
        $reconciliation->reject($t, (string) $request->validated('note'), $this->staff($request));

        return new JsonResponse(['data' => self::tx($t->refresh())]);
    }

    public function manualPayment(ManualPaymentRequest $request, string $order, Reconciliation $reconciliation): JsonResponse
    {
        $o = AdminLookup::find(PaymentOrder::class, $order);
        $d = $request->validated();
        $t = $reconciliation->manualPayment($o->id, (string) $d['bank_reference'], (int) $d['amount_minor'], (string) $d['note'], $this->staff($request));

        return new JsonResponse(['data' => ['transaction' => self::tx($t), 'order' => BillingPresenter::order(AdminLookup::find(PaymentOrder::class, $o->id))]], 201);
    }

    public function cancelOrder(Request $request, string $order, PaymentOrders $orders): JsonResponse
    {
        $o = AdminLookup::find(PaymentOrder::class, $order);

        return new JsonResponse(['data' => BillingPresenter::order($orders->cancel($o, $this->staff($request)))]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $q = DB::table('refunds')->orderByDesc('created_at')->orderByDesc('id');
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $q->where('status', $status);
        }

        return BillingPresenter::rows($q, fn (object $r) => [
            'id' => $r->id, 'workspace_id' => $r->workspace_id, 'payment_order_id' => $r->payment_order_id, 'amount_minor' => (int) $r->amount_minor,
            'currency' => $r->currency, 'status' => $r->status, 'reason' => $r->reason, 'credits_reversed_mc' => (int) $r->credits_reversed_mc,
            'external_ref' => $r->external_ref, 'failure_reason' => $r->failure_reason, 'created_at' => BillingPresenter::ts($r->created_at),
            'processed_at' => BillingPresenter::ts($r->processed_at),
        ], $request, 50);
    }

    public function requestRefund(RefundRequest $request, string $order, Refunds $refunds): JsonResponse
    {
        $o = AdminLookup::find(PaymentOrder::class, $order);
        $r = $refunds->request($o, (int) $request->validated('amount_minor'), (string) $request->validated('reason'), $this->staff($request));

        return new JsonResponse(['data' => BillingPresenter::refund($r)], 201);
    }

    public function completeRefund(CompleteRefundRequest $request, string $refund, Refunds $refunds): JsonResponse
    {
        $r = AdminLookup::find(Refund::class, $refund);
        $d = $request->validated();
        $r = $refunds->complete($r, (bool) $d['succeeded'], $d['external_ref'] ?? null, $d['failure_reason'] ?? null, $this->staff($request));

        return new JsonResponse(['data' => BillingPresenter::refund($r)]);
    }

    public function wallet(string $workspace, CreditLedger $ledger): JsonResponse
    {
        if (! DB::table('workspaces')->where('id', $workspace)->exists()) {
            throw ApiException::notFound();
        }
        $entries = DB::table('credit_ledger_entries')->where('workspace_id', $workspace)->orderByDesc('id')->limit(100)->get();

        return new JsonResponse(['data' => ['wallet' => $ledger->summary($workspace), 'entries' => $entries]]);
    }

    public function adjustments(Request $request): JsonResponse
    {
        return BillingPresenter::rows(DB::table('credit_adjustments')->orderByDesc('created_at')->orderByDesc('id'), fn (object $a) => [
            'id' => $a->id, 'workspace_id' => $a->workspace_id, 'amount_mc' => (int) $a->amount_mc, 'kind' => $a->kind, 'reason' => $a->reason,
            'expires_at' => BillingPresenter::ts($a->expires_at), 'ledger_entry_id' => $a->ledger_entry_id, 'created_by' => $a->created_by,
            'created_at' => BillingPresenter::ts($a->created_at),
        ], $request, 50);
    }

    public function adjust(CreditAdjustmentRequest $request, string $workspace, CreditLedger $ledger): JsonResponse
    {
        if (! DB::table('workspaces')->where('id', $workspace)->exists()) {
            throw ApiException::notFound();
        }
        $d = $request->validated();
        $staff = $this->staff($request);
        // One transaction: a refused debit leaves no orphan adjustment row.
        $adj = InTenant::run($workspace, fn (): CreditAdjustment => DB::transaction(function () use ($d, $staff, $ledger, $workspace): CreditAdjustment {
            $adj = new CreditAdjustment;
            $adj->forceFill([
                'amount_mc' => (int) $d['amount_mc'], 'kind' => $d['kind'], 'reason' => $d['reason'],
                'expires_at' => $d['expires_at'] ?? null, 'created_by' => $staff,
            ])->save();
            try {
                $entry = $ledger->adjust($workspace, (int) $d['amount_mc'], (string) $d['kind'], (string) $d['reason'], isset($d['expires_at']) ? CarbonImmutable::parse($d['expires_at']) : null, 'adj:'.$adj->id, $staff);
            } catch (InsufficientCredits $e) {
                throw new ApiException(422, 'insufficient_credits', __('billing.errors.adjust_below_zero'), ['available_mc' => $e->availableMc]);
            }
            $adj->forceFill(['ledger_entry_id' => $entry])->save();

            return $adj;
        }));
        Audit::record('billing.credit_adjusted', $adj, ['amount_mc' => $adj->amount_mc, 'kind' => $adj->kind, 'reason' => $adj->reason], $workspace, $staff, 'staff');

        return new JsonResponse(['data' => ['id' => $adj->id, 'ledger_entry_id' => $adj->ledger_entry_id, 'wallet' => $ledger->summary($workspace)]], 201);
    }

    /** AI calls whose provider outcome was unknown (charged at estimate, reviewable). */
    public function uncertainUsage(Request $request): JsonResponse
    {
        return BillingPresenter::rows(DB::table('credit_reservations')->where('needs_review', true)->orderByDesc('created_at')->orderByDesc('id'), fn (object $r) => [
            'id' => $r->id, 'workspace_id' => $r->workspace_id, 'purpose' => $r->purpose, 'category' => $r->category, 'status' => $r->status,
            'estimate_micro_usd' => (int) $r->estimate_micro_usd, 'actual_micro_usd' => $r->actual_micro_usd !== null ? (int) $r->actual_micro_usd : null,
            'charged_mc' => $r->charged_mc !== null ? (int) $r->charged_mc : null, 'uncollected_mc' => (int) $r->uncollected_mc,
            'created_at' => BillingPresenter::ts($r->created_at), 'settled_at' => BillingPresenter::ts($r->settled_at),
        ], $request, 50);
    }

    /**
     * @return array<string, mixed>
     */
    private static function tx(PaymentTransaction $t): array
    {
        return [
            'id' => $t->id, 'provider' => $t->provider, 'external_id' => $t->external_id, 'direction' => $t->direction, 'amount_minor' => $t->amount_minor,
            'currency' => $t->currency, 'description' => $t->description, 'counterparty' => $t->counterparty, 'occurred_at' => BillingPresenter::ts($t->occurred_at),
            'match_status' => $t->match_status, 'match_reason' => $t->match_reason, 'payment_order_id' => $t->payment_order_id, 'workspace_id' => $t->workspace_id,
            'created_at' => BillingPresenter::ts($t->created_at),
        ];
    }

    private function staff(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
