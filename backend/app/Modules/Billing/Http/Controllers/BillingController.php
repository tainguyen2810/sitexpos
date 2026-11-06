<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Domain\CreditReservation;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanVersion;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\SubscriptionChange;
use SiteXPost\Modules\Billing\Domain\SubscriptionEvent;
use SiteXPost\Modules\Billing\Entitlements\EntitlementEngine;
use SiteXPost\Modules\Billing\Http\BillingPresenter;
use SiteXPost\Modules\Billing\Http\Requests\CancelSubscriptionRequest;
use SiteXPost\Modules\Billing\Http\Requests\ChangePlanRequest;
use SiteXPost\Modules\Billing\Http\Requests\CheckoutRequest;
use SiteXPost\Modules\Billing\Http\Requests\PlanCodeRequest;
use SiteXPost\Modules\Billing\Http\Requests\RenewRequest;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionManager;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\Catalog;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Customer billing: pricing, current subscription, trial, checkout,
 * upgrade/downgrade (quote → confirm), cancel/resume/renew, AI usage.
 */
final class BillingController
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly SubscriptionManager $subscriptions,
        private readonly PaymentOrders $orders,
        private readonly EntitlementEngine $entitlements,
        private readonly BillingSettings $settings,
    ) {}

    public function plans(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.view');
        $owner = User::query()->find($workspace->owner_id);
        $plans = Plan::query()->where('status', 'active')->orderBy('display_order')->orderBy('name')->get()
            ->map(function (Plan $p) use ($workspace, $owner): ?array {
                $v = $this->catalog->currentVersion($p);
                if ($v === null) {
                    return null;
                }
                $row = BillingPresenter::plan($p, $v);
                $row['trial'] = $owner !== null ? $this->subscriptions->trialEligibility($workspace->id, $owner, $v) : ['eligible' => false, 'reason' => 'no_owner'];

                return $row;
            })->filter()->values();

        return new JsonResponse(['data' => [
            'plans' => $plans,
            'credit_packages' => CreditPackage::query()->where('status', 'active')->orderBy('display_order')->get()->map(fn ($p) => BillingPresenter::package($p)),
            'providers' => PaymentProviderConfig::query()->where('is_enabled', true)->orderBy('display_order')->get()->map(fn ($c) => BillingPresenter::publicProvider($c)),
            'currency' => $this->settings->get('currency'),
            'proration_enabled' => $this->settings->bool('proration_enabled'),
        ]]);
    }

    public function show(Workspace $workspace, CreditLedger $ledger): JsonResponse
    {
        Gate::authorize('billing.view');
        $sub = $this->subscriptions->live($workspace->id);
        $last = $sub ?? Subscription::query()->orderByDesc('created_at')->first();
        $change = $sub !== null ? SubscriptionChange::query()->where('subscription_id', $sub->id)->whereIn('status', ['scheduled', 'pending_payment'])->latest()->first() : null;
        $effective = $this->entitlements->effective($workspace->id);

        return new JsonResponse(['data' => [
            'subscription' => $sub !== null ? BillingPresenter::subscription($sub) : null,
            'last_subscription' => $sub === null && $last !== null ? BillingPresenter::subscription($last) : null,
            'pending_change' => $change !== null ? BillingPresenter::change($change) : null,
            'entitlements' => $effective + ['usage' => $this->entitlements->usage($workspace->id)],
            'wallet' => $ledger->summary($workspace->id),
            'history' => $last !== null ? SubscriptionEvent::query()->where('subscription_id', $last->id)->orderByDesc('id')->limit(50)->get()->map(fn ($e) => BillingPresenter::event($e)) : [],
            'grace_allows_ai' => $this->settings->bool('grace_allows_ai'),
        ]]);
    }

    public function history(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.view');

        return BillingPresenter::page(SubscriptionEvent::query()->orderByDesc('id'), fn ($e) => BillingPresenter::event($e), $request, 50);
    }

    public function trial(PlanCodeRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        [$plan, $version] = $this->catalog->sellable((string) $request->validated('plan_code'));
        $owner = User::query()->findOrFail($workspace->owner_id);
        $sub = $this->subscriptions->startTrial($workspace->id, $owner, $plan, $version, $this->userId($request));

        return new JsonResponse(['data' => BillingPresenter::subscription($sub)], 201);
    }

    public function subscribeFree(PlanCodeRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        [$plan, $version] = $this->catalog->sellable((string) $request->validated('plan_code'));
        $sub = $this->subscriptions->subscribeFree($workspace->id, $plan, $version, $this->userId($request));

        return new JsonResponse(['data' => BillingPresenter::subscription($sub)], 201);
    }

    public function checkout(CheckoutRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $d = $request->validated();
        $order = $this->orders->forSubscription($workspace->id, (string) $d['plan_code'], (string) $d['interval'], $d['provider'] ?? null, $d['idempotency_key'] ?? null, $this->userId($request));

        return new JsonResponse(['data' => BillingPresenter::order($order, $this->orders->instructions($order))], 201);
    }

    public function quote(ChangePlanRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        [$sub, $version, $interval] = $this->changeTarget($request, $workspace);

        return new JsonResponse(['data' => $this->subscriptions->quote($sub, $version, $interval)]);
    }

    public function change(ChangePlanRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        [$sub, $version, $interval] = $this->changeTarget($request, $workspace);
        $change = $this->subscriptions->requestChange($sub, $version, $interval, $this->userId($request));
        $order = null;
        if ($change->status === 'pending_payment' && $change->amount_minor > 0) {
            $order = $this->orders->forChange($change, $request->validated('provider'), $this->userId($request));
        }

        return new JsonResponse(['data' => [
            'change' => BillingPresenter::change($change->refresh()),
            'order' => $order !== null ? BillingPresenter::order($order, $this->orders->instructions($order)) : null,
        ]], 201);
    }

    public function cancelChange(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $sub = $this->subscriptions->live($workspace->id) ?? throw ApiException::notFound();
        $this->subscriptions->cancelScheduledChange($sub, $this->userId($request));

        return new JsonResponse(null, 204);
    }

    public function cancel(CancelSubscriptionRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $sub = $this->subscriptions->live($workspace->id) ?? throw ApiException::notFound();

        return new JsonResponse(['data' => BillingPresenter::subscription($this->subscriptions->cancel($sub, $this->userId($request), $request->validated('reason')))]);
    }

    public function resume(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $sub = $this->subscriptions->live($workspace->id) ?? throw ApiException::notFound();

        return new JsonResponse(['data' => BillingPresenter::subscription($this->subscriptions->resume($sub, $this->userId($request)))]);
    }

    public function renew(RenewRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $order = $this->orders->forRenewal($workspace->id, $request->validated('provider'), $request->validated('idempotency_key'), $this->userId($request));

        return new JsonResponse(['data' => BillingPresenter::order($order, $this->orders->instructions($order))], 201);
    }

    public function settings(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $data = $request->validate(['renewal_reminders' => ['required', 'boolean']]);
        $sub = $this->subscriptions->live($workspace->id) ?? throw ApiException::notFound();

        return new JsonResponse(['data' => BillingPresenter::subscription($this->subscriptions->setReminders($sub, (bool) $data['renewal_reminders']))]);
    }

    /**
     * AI usage & credits consumed this month, by category (from the
     * workspace's own credit reservations — real numbers only).
     */
    public function usage(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.view');
        $from = now()->startOfMonth();
        $rows = CreditReservation::query()->where('created_at', '>=', $from)->whereIn('status', ['settled', 'uncertain', 'expired'])
            ->select('category', DB::raw('COUNT(*) AS calls'), DB::raw('SUM(charged_mc) AS charged'), DB::raw('SUM(CASE WHEN needs_review THEN 1 ELSE 0 END) AS uncertain'))
            ->groupBy('category')->get()
            ->map(fn ($r) => ['category' => $r->getAttribute('category') ?? 'other', 'calls' => (int) $r->getAttribute('calls'), 'charged_mc' => (int) $r->getAttribute('charged'), 'uncertain_calls' => (int) $r->getAttribute('uncertain')]);
        $daily = CreditReservation::query()->where('created_at', '>=', $from)->whereIn('status', ['settled', 'uncertain', 'expired'])
            ->get(['created_at', 'charged_mc'])->groupBy(fn (CreditReservation $r) => $r->created_at?->toDateString())
            ->map(fn ($g, $day) => ['day' => $day, 'charged_mc' => (int) $g->sum('charged_mc')])->values();

        return new JsonResponse(['data' => [
            'period_start' => $from->toIso8601ZuluString(),
            'by_category' => $rows,
            'daily' => $daily,
            'entitlements' => $this->entitlements->effective($workspace->id)['limits'],
            'usage' => $this->entitlements->usage($workspace->id),
        ]]);
    }

    /**
     * @return array{0: Subscription, 1: PlanVersion, 2: string}
     */
    private function changeTarget(ChangePlanRequest $request, Workspace $workspace): array
    {
        $sub = $this->subscriptions->live($workspace->id) ?? throw new ApiException(422, 'no_subscription', __('billing.errors.no_subscription'));
        if (in_array($sub->status, ['PAUSED', 'SUSPENDED'], true)) {
            throw new ApiException(422, 'subscription_on_hold', __('billing.errors.subscription_on_hold'));
        }
        [, $version] = $this->catalog->sellable((string) $request->validated('plan_code'));

        return [$sub, $version, (string) $request->validated('interval')];
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
