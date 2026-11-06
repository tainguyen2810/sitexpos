<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\CreditGrant;
use SiteXPost\Modules\Billing\Domain\CreditLedgerEntry;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Http\BillingPresenter;
use SiteXPost\Modules\Billing\Http\Requests\BuyCreditsRequest;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Credit wallet, ledger history and credit purchase.
 */
final class CreditController
{
    public function wallet(Workspace $workspace, CreditLedger $ledger): JsonResponse
    {
        Gate::authorize('billing.view');

        return new JsonResponse(['data' => [
            'wallet' => $ledger->summary($workspace->id),
            'grants' => CreditGrant::query()->where('remaining_mc', '>', 0)->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->limit(100)->get()->map(fn ($g) => BillingPresenter::grant($g)),
        ]]);
    }

    public function transactions(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.view');
        $q = CreditLedgerEntry::query()->orderByDesc('id');
        $type = $request->query('type');
        if (is_string($type) && in_array($type, ['grant', 'consume', 'expire', 'refund', 'adjust', 'reversal'], true)) {
            $q->where('entry_type', $type);
        }

        return BillingPresenter::page($q, fn ($e) => BillingPresenter::entry($e), $request, 50);
    }

    public function packages(Workspace $workspace): JsonResponse
    {
        Gate::authorize('billing.view');

        return new JsonResponse(['data' => CreditPackage::query()->where('status', 'active')->orderBy('display_order')->get()->map(fn ($p) => BillingPresenter::package($p))]);
    }

    public function purchase(BuyCreditsRequest $request, Workspace $workspace, PaymentOrders $orders): JsonResponse
    {
        Gate::authorize('billing.manage');
        $d = $request->validated();
        $order = $orders->forCreditPackage($workspace->id, (string) $d['package_code'], $d['provider'] ?? null, $d['idempotency_key'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => BillingPresenter::order($order, $orders->instructions($order))], 201);
    }
}
