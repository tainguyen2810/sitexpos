<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Entitlements;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanVersion;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\UsageEvent;
use SiteXPost\Modules\Billing\Domain\UsageMeter;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionStatus;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\Catalog;
use SiteXPost\Modules\Billing\Support\InTenant;

/**
 * Entitlement engine (BILLING-ARCHITECTURE.md §Entitlements). Resolution:
 * live subscription → its plan version; else the configured default plan;
 * else nothing (allowed while enforcement is off, blocked when it is on).
 */
final class EntitlementEngine implements Entitlements
{
    public function __construct(private readonly BillingSettings $settings, private readonly Catalog $catalog) {}

    /**
     * @return array{source: string, enforcing: bool, applies: bool, status: string|null, plan_id: string|null, plan_code: string|null, plan_name: string|null, version_id: string|null, limits: array<string, array{limit: int, period: string}>, features: array<string, bool>, paid_usage_allowed: bool, subscription_id: string|null}
     */
    public function effective(string $workspaceId): array
    {
        $enforcing = $this->settings->enforcing();
        $sub = InTenant::run($workspaceId, fn (): ?Subscription => Subscription::query()->where('live_key', $workspaceId)->first());
        $version = null;
        $status = null;
        $source = 'none';
        if ($sub !== null) {
            $version = PlanVersion::query()->find($sub->plan_version_id);
            $status = $sub->status;
            $source = 'subscription';
        } elseif (is_string($code = $this->settings->get('default_plan_code')) && $code !== '') {
            $plan = Plan::query()->where('code', $code)->where('status', '!=', 'archived')->first();
            $version = $plan !== null ? $this->catalog->currentVersion($plan) : null;
            if ($version !== null) {
                $status = SubscriptionStatus::Active->value;
                $source = 'default_plan';
            }
        }
        $plan = $version !== null ? Plan::query()->find($version->plan_id) : null;
        $applies = $source !== 'none' || $enforcing;
        $paid = $source === 'none'
            ? ! $enforcing
            : SubscriptionStatus::from((string) $status)->allowsPaidUsage($this->settings->bool('grace_allows_ai'));

        return [
            'source' => $source,
            'enforcing' => $enforcing,
            'applies' => $applies,
            'status' => $status,
            'plan_id' => $plan?->id,
            'plan_code' => $plan?->code,
            'plan_name' => $plan?->name,
            'version_id' => $version?->id,
            'limits' => $version !== null ? $this->catalog->entitlements($version->id) : [],
            'features' => $version !== null ? Catalog::features($version) : ($applies ? array_map(fn () => false, Catalog::FEATURES) : Catalog::FEATURES),
            'paid_usage_allowed' => $paid,
            'subscription_id' => $sub?->id,
        ];
    }

    public function limit(string $workspaceId, string $resource): ?int
    {
        $e = $this->effective($workspaceId);
        if (! $e['applies']) {
            return null;
        }
        if ($e['source'] === 'none') {
            return 0; // enforcing without any plan: nothing is included
        }
        $l = $e['limits'][$resource]['limit'] ?? null;
        if ($l === null || $l < 0) {
            return null; // not limited by this plan / unlimited
        }

        return $l;
    }

    public function feature(string $workspaceId, string $flag): bool
    {
        $e = $this->effective($workspaceId);

        return ! $e['applies'] || (bool) ($e['features'][$flag] ?? false);
    }

    public function assertActive(string $workspaceId, ?string $feature = null): void
    {
        $e = $this->effective($workspaceId);
        if (! $e['applies']) {
            return;
        }
        if ($e['source'] === 'none') {
            throw new ApiException(402, 'subscription_required', __('billing.errors.subscription_required'));
        }
        if (! $e['paid_usage_allowed']) {
            throw new ApiException(402, 'subscription_inactive', __('billing.errors.subscription_inactive'), ['status' => $e['status']]);
        }
        if ($feature !== null && ! ($e['features'][$feature] ?? false)) {
            throw new ApiException(402, 'feature_unavailable', __('billing.errors.feature_unavailable'), ['feature' => $feature, 'plan' => $e['plan_code']]);
        }
    }

    public function assertWithin(string $workspaceId, string $resource, int $current, int $adding = 1): void
    {
        $limit = $this->limit($workspaceId, $resource);
        if ($limit !== null && $current + $adding > $limit) {
            if ($this->effective($workspaceId)['source'] === 'none') {
                throw new ApiException(402, 'subscription_required', __('billing.errors.subscription_required'));
            }
            throw self::limitReached($resource, $limit, $current);
        }
    }

    public function consume(string $workspaceId, string $resource, int $amount, string $idempotencyKey): void
    {
        $limit = $this->limit($workspaceId, $resource);
        $period = now()->startOfMonth()->toDateString();
        InTenant::run($workspaceId, function () use ($resource, $amount, $idempotencyKey, $limit, $period): void {
            $this->ensureMeter($resource, $period);
            DB::transaction(function () use ($resource, $amount, $idempotencyKey, $limit, $period): void {
                $meter = UsageMeter::query()->where('resource_key', $resource)->where('period_start', $period)->lockForUpdate()->firstOrFail();
                if (UsageEvent::query()->where('idempotency_key', 'use:'.$idempotencyKey)->exists()) {
                    return; // already counted
                }
                if ($limit !== null && $meter->used + $amount > $limit) {
                    throw self::limitReached($resource, $limit, $meter->used);
                }
                $meter->forceFill(['used' => $meter->used + $amount])->save();
                $this->event($resource, $period, $amount, 'use:'.$idempotencyKey);
            });
        });
    }

    public function release(string $workspaceId, string $resource, int $amount, string $idempotencyKey): void
    {
        InTenant::run($workspaceId, function () use ($resource, $amount, $idempotencyKey): void {
            DB::transaction(function () use ($resource, $amount, $idempotencyKey): void {
                $use = UsageEvent::query()->where('idempotency_key', 'use:'.$idempotencyKey)->first();
                if ($use === null || UsageEvent::query()->where('idempotency_key', 'release:'.$idempotencyKey)->exists()) {
                    return;
                }
                $meter = UsageMeter::query()->where('resource_key', $resource)->where('period_start', $use->period_start)->lockForUpdate()->first();
                if ($meter === null) {
                    return;
                }
                $back = min($amount, $use->amount, $meter->used);
                $meter->forceFill(['used' => $meter->used - $back])->save();
                $this->event($resource, $use->period_start, -$back, 'release:'.$idempotencyKey);
            });
        });
    }

    /**
     * @return array<string, int>
     */
    public function usage(string $workspaceId): array
    {
        $period = now()->startOfMonth()->toDateString();

        return InTenant::run($workspaceId, fn (): array => UsageMeter::query()->where('period_start', $period)->pluck('used', 'resource_key')->map(fn ($v) => (int) $v)->all());
    }

    public static function limitReached(string $resource, int $limit, int $used): ApiException
    {
        return new ApiException(402, 'plan_limit_reached', __('billing.errors.plan_limit_reached', ['resource' => __('billing.resources.'.$resource), 'limit' => $limit]), ['resource' => $resource, 'limit' => $limit, 'used' => $used]);
    }

    private function ensureMeter(string $resource, string $period): void
    {
        if (UsageMeter::query()->where('resource_key', $resource)->where('period_start', $period)->exists()) {
            return;
        }
        try {
            $m = new UsageMeter;
            $m->forceFill(['resource_key' => $resource, 'period_start' => $period, 'used' => 0])->save();
        } catch (QueryException) {
            // created concurrently
        }
    }

    private function event(string $resource, string $period, int $amount, string $key): void
    {
        $e = new UsageEvent;
        $e->forceFill(['resource_key' => $resource, 'period_start' => $period, 'amount' => $amount, 'idempotency_key' => mb_substr($key, 0, 191), 'created_at' => now()])->save();
    }
}
