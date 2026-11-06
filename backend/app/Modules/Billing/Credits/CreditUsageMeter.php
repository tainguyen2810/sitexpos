<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Credits;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\AiGateway\Contracts\UsageDenied;
use SiteXPost\Modules\AiGateway\Contracts\UsageMeter;
use SiteXPost\Modules\Billing\Contracts\CreditMeter;
use SiteXPost\Modules\Billing\Entitlements\EntitlementEngine;
use SiteXPost\Modules\Billing\Support\BillingNotifier;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\CostCategory;

/**
 * Phase 3 ⇄ Phase 7 bridge. Workflow (CREDIT-LEDGER.md §Reservation):
 * estimate (gateway) → entitlement check → reserve credits → provider call
 * → actual usage → settle (charge actual, release the rest) → ledger.
 *
 * Metering applies when the workspace is on a plan (subscription or default
 * plan) or enforcement is on. Workspaces outside billing (enforcement off,
 * no plan) are not metered — exactly the Phase 3–6 behaviour.
 */
final class CreditUsageMeter implements CreditMeter, UsageMeter
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly CreditConverter $converter,
        private readonly EntitlementEngine $entitlements,
        private readonly BillingSettings $settings,
        private readonly BillingNotifier $notifier,
    ) {}

    // ---- AI Gateway contract

    public function reserve(string $key, string $workspaceId, string $taskKey, int $estimateMicroUsd): void
    {
        $e = $this->entitlements->effective($workspaceId);
        if (! $e['applies']) {
            return;
        }
        try {
            $this->entitlements->assertActive($workspaceId, CostCategory::feature($taskKey));
        } catch (ApiException $ex) {
            throw new UsageDenied($ex->errorCode, $ex->getMessage(), $ex->details);
        }
        $policy = $this->converter->current();
        if ($policy === null) {
            throw new UsageDenied('credit_policy_missing', __('billing.errors.credit_policy_missing'));
        }
        try {
            $this->ledger->reserve($workspaceId, $key, $taskKey, CostCategory::of($taskKey), $estimateMicroUsd, $policy);
        } catch (InsufficientCredits $ex) {
            throw new UsageDenied('insufficient_credits', __('billing.errors.insufficient_credits'), ['required_mc' => $ex->requiredMc, 'available_mc' => $ex->availableMc]);
        }
    }

    public function settle(string $key, int $actualMicroUsd, string $outcome = 'settled'): bool
    {
        $done = $this->ledger->settle($key, $actualMicroUsd, $outcome === 'uncertain' ? 'uncertain' : 'settled');
        if ($done) {
            $this->lowBalanceCheck($key);
        }

        return $done;
    }

    public function release(string $key): bool
    {
        return $this->ledger->release($key);
    }

    // ---- generic CreditMeter

    public function balance(string $workspaceId): array
    {
        return $this->ledger->summary($workspaceId);
    }

    /**
     * @throws InsufficientCredits
     */
    public function reserveCredits(string $workspaceId, string $key, string $purpose, int $estimateMicroUsd): bool
    {
        return $this->ledger->reserve($workspaceId, $key, $purpose, CostCategory::of($purpose), $estimateMicroUsd)[1];
    }

    private function lowBalanceCheck(string $key): void
    {
        $row = DB::table('credit_reservations')->where('idempotency_key', $key)->first(['workspace_id']);
        if ($row === null) {
            return;
        }
        $ws = (string) $row->workspace_id;
        $summary = $this->ledger->summary($ws);
        $threshold = $this->settings->int('low_credit_threshold_mc');
        if ($threshold > 0 && $summary['available_mc'] < $threshold) {
            // One notice per workspace per day at most.
            $this->notifier->send($ws, 'low_credits', 'low_credits:'.$ws.':'.now()->toDateString(), ['available_mc' => $summary['available_mc']]);
        }
    }
}
