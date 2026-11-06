<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Contracts;

use SiteXPost\Modules\Billing\Credits\InsufficientCredits;

/**
 * Generic credit reservation API for any metered action (AI Gateway uses it
 * through `CreditUsageMeter`; later phases can reuse it without touching
 * billing tables). reserve → settle | release, all keyed by an idempotency
 * key: repeating any call with the same key never moves credits twice.
 */
interface CreditMeter
{
    /**
     * Hold credits for an estimated provider cost (micro-USD), converted with
     * the current credit policy (frozen on the reservation).
     *
     * @return bool true when a new hold was created, false when the key already existed
     *
     * @throws InsufficientCredits
     */
    public function reserveCredits(string $workspaceId, string $key, string $purpose, int $estimateMicroUsd): bool;

    /** Charge the actual cost; `uncertain` bills max(actual, estimate) and flags it for review. */
    public function settle(string $key, int $actualMicroUsd, string $outcome = 'settled'): bool;

    public function release(string $key): bool;

    /**
     * @return array{balance_mc: int, held_mc: int, available_mc: int, overdraft_limit_mc: int}
     */
    public function balance(string $workspaceId): array;
}
