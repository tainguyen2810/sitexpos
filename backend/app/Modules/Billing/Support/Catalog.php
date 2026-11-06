<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanEntitlement;
use SiteXPost\Modules\Billing\Domain\PlanVersion;

/**
 * Read side of the plan catalog. Prices and limits come only from plan
 * versions created by staff — nothing is hardcoded here.
 */
final class Catalog
{
    /** Resource keys a plan can limit. `total` = count of existing rows, `month` = usage per calendar month (UTC). */
    public const RESOURCES = [
        'websites' => 'total',
        'members' => 'total',
        'automation_schedules' => 'total',
        'storage_mb' => 'total',
        'research_runs' => 'month',
        'keywords' => 'month',
        'articles' => 'month',
        'ai_images' => 'month',
        'publishing_jobs' => 'month',
    ];

    /** Feature flags and their value when a plan version does not mention them. */
    public const FEATURES = [
        'ai_writer' => true,
        'ai_images' => true,
        'research' => true,
        'publishing' => true,
        'automation' => false,
        'auto_publish' => false,
        'priority_support' => false,
    ];

    public const SUPPORT_LEVELS = ['community', 'email', 'priority', 'dedicated'];

    public function version(string $versionId): PlanVersion
    {
        return PlanVersion::query()->find($versionId) ?? throw ApiException::notFound();
    }

    public function plan(string $planId): Plan
    {
        return Plan::query()->find($planId) ?? throw ApiException::notFound();
    }

    public function currentVersion(Plan $plan): ?PlanVersion
    {
        return $plan->current_version_id !== null
            ? PlanVersion::query()->where('id', $plan->current_version_id)->where('status', 'published')->first()
            : null;
    }

    /**
     * Sellable plan + its current published version, or 404/422.
     *
     * @return array{0: Plan, 1: PlanVersion}
     */
    public function sellable(string $planCode): array
    {
        $plan = Plan::query()->where('code', $planCode)->where('status', 'active')->first() ?? throw ApiException::notFound();
        $version = $this->currentVersion($plan) ?? throw new ApiException(422, 'plan_not_available', __('billing.errors.plan_not_available'));

        return [$plan, $version];
    }

    public static function price(PlanVersion $v, string $interval): ?int
    {
        return $interval === 'year' ? $v->yearly_price_minor : $v->monthly_price_minor;
    }

    public static function isFree(Plan $plan, PlanVersion $v): bool
    {
        return $plan->is_free && (int) $v->monthly_price_minor === 0 && in_array($v->yearly_price_minor, [null, 0], true);
    }

    /** Monthly-equivalent price, used to decide upgrade vs downgrade. */
    public static function monthlyEquivalent(PlanVersion $v, string $interval): int
    {
        $p = (int) self::price($v, $interval);

        return $interval === 'year' ? intdiv($p, 12) : $p;
    }

    /**
     * @return array<string, array{limit: int, period: string}>
     */
    public function entitlements(string $versionId): array
    {
        return PlanEntitlement::query()->where('plan_version_id', $versionId)->get()
            ->mapWithKeys(fn (PlanEntitlement $e) => [$e->resource_key => ['limit' => $e->limit_value, 'period' => $e->period]])->all();
    }

    /**
     * @return array<string, bool>
     */
    public static function features(PlanVersion $v): array
    {
        $flags = is_array($v->feature_flags) ? $v->feature_flags : [];
        $out = [];
        foreach (self::FEATURES as $k => $default) {
            $out[$k] = array_key_exists($k, $flags) ? (bool) $flags[$k] : $default;
        }

        return $out;
    }
}
