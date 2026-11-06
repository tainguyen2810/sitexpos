<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Contracts;

/**
 * Hook for the commercial layer (Phase 7 Billing binds `CreditUsageMeter`).
 * The gateway calls it with the SAME idempotency key it uses for its own
 * budget reservation (call key + attempt), so a queue retry of the same call
 * can never be reserved or charged twice. The gateway itself knows nothing
 * about credits or plans; without Billing the `NullUsageMeter` is used.
 */
interface UsageMeter
{
    /**
     * Hold the estimated cost before the provider call.
     *
     * @throws UsageDenied when the workspace may not run this paid call
     */
    public function reserve(string $key, string $workspaceId, string $taskKey, int $estimateMicroUsd): void;

    /**
     * Charge the real cost. `outcome`: settled (usage known) | uncertain
     * (the provider may have charged; never assumed free).
     */
    public function settle(string $key, int $actualMicroUsd, string $outcome = 'settled'): bool;

    /** Nothing was charged by the provider: release the hold. */
    public function release(string $key): bool;
}
