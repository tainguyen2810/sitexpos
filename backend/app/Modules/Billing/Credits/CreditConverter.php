<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Credits;

use SiteXPost\Modules\Billing\Domain\CreditPolicy;

/**
 * AI provider cost (micro-USD, Phase 3 versioned prices) → credits charged
 * (milli-credits). Integer arithmetic only; always rounds UP to the next
 * milli-credit so a call is never charged less than its cost basis.
 *
 *   mc = ceil(cost × (10000 + markup_bps) × 1000 / (10000 × micro_usd_per_credit))
 *
 * The policy is frozen on each reservation → a new policy never changes the
 * price of a call already reserved or settled (no retroactive pricing).
 */
final class CreditConverter
{
    public function current(): ?CreditPolicy
    {
        return CreditPolicy::query()->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    public static function toCredits(int $costMicroUsd, CreditPolicy $policy): int
    {
        if ($costMicroUsd <= 0) {
            return 0;
        }
        $num = $costMicroUsd * (10_000 + $policy->markup_bps) * 1000;
        $den = 10_000 * max(1, $policy->micro_usd_per_credit);
        $mc = intdiv($num + $den - 1, $den);

        return max($mc, $policy->min_charge_mc);
    }
}
