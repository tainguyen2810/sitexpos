<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Contracts;

use SiteXPost\Kernel\Http\ApiException;

/**
 * Backend entitlement checks for every module (never trust the frontend).
 *
 * While Billing enforcement is "off" (no catalog configured yet) and a
 * workspace has no subscription, every method allows and `limit()` returns
 * null → modules keep their Phase 4–6 interim limits.
 */
interface Entitlements
{
    /**
     * Plan limit for a resource; null = no billing limit (unlimited or not enforced).
     */
    public function limit(string $workspaceId, string $resource): ?int;

    public function feature(string $workspaceId, string $flag): bool;

    /**
     * May the workspace start new paid work (AI, automation, publishing jobs)?
     *
     * @throws ApiException 402 subscription_required / subscription_inactive / feature_unavailable
     */
    public function assertActive(string $workspaceId, ?string $feature = null): void;

    /**
     * Check a "total" resource (websites, members, automation schedules,
     * storage) given the current count and how many will be added.
     *
     * @throws ApiException 402 plan_limit_reached
     */
    public function assertWithin(string $workspaceId, string $resource, int $current, int $adding = 1): void;

    /**
     * Count monthly usage (articles, research runs, AI images, publishing
     * jobs, keywords) atomically. Idempotent per key.
     *
     * @throws ApiException 402 plan_limit_reached
     */
    public function consume(string $workspaceId, string $resource, int $amount, string $idempotencyKey): void;

    /** Give back usage counted by `consume()` (failed / cancelled work). Idempotent per key. */
    public function release(string $workspaceId, string $resource, int $amount, string $idempotencyKey): void;
}
