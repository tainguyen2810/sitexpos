<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Subscriptions;

use Carbon\CarbonInterface;

/**
 * Upgrade proration (only when Billing Settings → proration_enabled).
 * Integer seconds, rounded DOWN (in the customer's favour):
 *
 *   charge = max(0, floor((new − old) × remaining / total))
 *
 * Downgrades are never prorated: they are scheduled for the period end.
 */
final class Proration
{
    /**
     * @return array{charge_minor: int, remaining_seconds: int, total_seconds: int, remaining_days: int, credit_for_unused_minor: int, new_price_minor: int}
     */
    public static function upgrade(int $oldPriceMinor, int $newPriceMinor, CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonInterface $now): array
    {
        $total = max(1, $periodEnd->getTimestamp() - $periodStart->getTimestamp());
        $remaining = max(0, min($total, $periodEnd->getTimestamp() - $now->getTimestamp()));
        $unused = intdiv($oldPriceMinor * $remaining, $total);

        return [
            'charge_minor' => intdiv(max(0, $newPriceMinor - $oldPriceMinor) * $remaining, $total),
            'remaining_seconds' => $remaining,
            'total_seconds' => $total,
            'remaining_days' => intdiv($remaining, 86400),
            'credit_for_unused_minor' => $unused,
            'new_price_minor' => $newPriceMinor,
        ];
    }
}
