<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Domain\BillingDocument;
use SiteXPost\Modules\Billing\Domain\BillingProfile;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Http\BillingPresenter;
use SiteXPost\Modules\Billing\Http\Requests\BillingProfileRequest;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Payment orders (status polling, VietQR instructions), billing documents
 * and billing profile. The status shown is always the server's: the client
 * can never mark an order paid.
 */
final class OrderController
{
    public function __construct(private readonly PaymentOrders $orders) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $q = PaymentOrder::query()->orderByDesc('created_at')->orderByDesc('id');
        $status = $request->query('status');
        if (is_string($status) && in_array($status, PaymentOrders::STATUSES, true)) {
            $q->where('status', $status);
        }

        return BillingPresenter::page($q, fn ($o) => BillingPresenter::order($o), $request, 25);
    }

    public function show(Workspace $workspace, PaymentOrder $paymentOrder): JsonResponse
    {
        Gate::authorize('billing.manage');
        $payable = in_array($paymentOrder->status, ['PENDING', 'PROCESSING'], true);

        return new JsonResponse(['data' => BillingPresenter::order($paymentOrder, $payable ? $this->orders->instructions($paymentOrder) : null) + [
            'receipt' => BillingDocument::query()->where('source_key', 'receipt:'.$paymentOrder->id)->first()?->only(['id', 'number']),
        ]]);
    }

    public function transferred(Workspace $workspace, PaymentOrder $paymentOrder): JsonResponse
    {
        Gate::authorize('billing.manage');

        return new JsonResponse(['data' => BillingPresenter::order($this->orders->markProcessing($paymentOrder))]);
    }

    public function cancel(Request $request, Workspace $workspace, PaymentOrder $paymentOrder): JsonResponse
    {
        Gate::authorize('billing.manage');

        return new JsonResponse(['data' => BillingPresenter::order($this->orders->cancel($paymentOrder, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function documents(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');

        return BillingPresenter::page(BillingDocument::query()->orderByDesc('issued_at')->orderByDesc('id'), fn ($d) => BillingPresenter::document($d), $request, 25);
    }

    public function document(Workspace $workspace, BillingDocument $billingDocument): JsonResponse
    {
        Gate::authorize('billing.manage');

        return new JsonResponse(['data' => BillingPresenter::document($billingDocument)]);
    }

    public function profile(Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');

        return new JsonResponse(['data' => BillingPresenter::profile(BillingProfile::query()->first())]);
    }

    public function updateProfile(BillingProfileRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.manage');
        $profile = BillingProfile::query()->first() ?? new BillingProfile;
        $profile->forceFill($request->validated())->save();
        Audit::record('billing.profile_updated', $profile, ['fields' => array_keys($request->validated())]);

        return new JsonResponse(['data' => BillingPresenter::profile($profile)]);
    }
}
