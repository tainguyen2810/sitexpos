<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Domain\CreditPolicy;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanEntitlement;
use SiteXPost\Modules\Billing\Domain\PlanVersion;
use SiteXPost\Modules\Billing\Http\BillingPresenter;
use SiteXPost\Modules\Billing\Http\Requests\Admin\CreditPackageRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\CreditPolicyRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\PlanRequest;
use SiteXPost\Modules\Billing\Http\Requests\Admin\PlanVersionRequest;
use SiteXPost\Modules\Billing\Support\Catalog;

/**
 * Plans Management, Pricing Versions, credit packages and the credit
 * conversion policy. Published versions are immutable; edits = new version.
 * A plan in use can be hidden/archived but never deleted.
 */
final class AdminCatalogController
{
    public function __construct(private readonly Catalog $catalog) {}

    public function plans(): JsonResponse
    {
        $counts = DB::table('subscriptions')->whereNotNull('live_key')->selectRaw('plan_id, COUNT(*) AS n')->groupBy('plan_id')->pluck('n', 'plan_id');

        return new JsonResponse(['data' => Plan::query()->orderBy('display_order')->orderBy('name')->get()->map(function (Plan $p) use ($counts) {
            $versions = PlanVersion::query()->where('plan_id', $p->id)->orderByDesc('version')->get()
                ->map(fn (PlanVersion $v) => BillingPresenter::version($v, $this->catalog->entitlements($v->id)));

            return BillingPresenter::plan($p, $this->catalog->currentVersion($p), true) + ['versions' => $versions, 'live_subscriptions' => (int) ($counts[$p->id] ?? 0)];
        })]);
    }

    public function storePlan(PlanRequest $request): JsonResponse
    {
        $plan = new Plan;
        $plan->forceFill($request->validated() + ['status' => 'draft'])->save();
        Audit::record('billing.plan_created', $plan, $request->validated(), null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::plan($plan, null, true)], 201);
    }

    public function updatePlan(PlanRequest $request, string $plan): JsonResponse
    {
        $p = Plan::query()->findOrFail($plan);
        $data = $request->validated();
        if (($data['status'] ?? null) === 'active' && $this->catalog->currentVersion($p) === null) {
            throw new ApiException(422, 'plan_has_no_published_version', __('billing.errors.plan_has_no_published_version'));
        }
        unset($data['code']); // the code is an identifier, never renamed
        $p->forceFill($data)->save();
        Audit::record('billing.plan_updated', $p, $data, null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::plan($p, $this->catalog->currentVersion($p), true)]);
    }

    public function destroyPlan(Request $request, string $plan): JsonResponse
    {
        $p = Plan::query()->findOrFail($plan);
        if (DB::table('subscriptions')->where('plan_id', $p->id)->exists() || DB::table('payment_orders')->whereIn('plan_version_id', PlanVersion::query()->where('plan_id', $p->id)->pluck('id'))->exists()) {
            throw ApiException::conflict('plan_in_use', __('billing.errors.plan_in_use'));
        }
        DB::transaction(function () use ($p): void {
            PlanVersion::query()->where('plan_id', $p->id)->get()->each(fn (PlanVersion $v) => $v->delete());
            $p->delete();
        });
        Audit::record('billing.plan_deleted', null, ['code' => $p->code], null, $this->staff($request), 'staff');

        return new JsonResponse(null, 204);
    }

    public function storeVersion(PlanVersionRequest $request, string $plan): JsonResponse
    {
        $p = Plan::query()->findOrFail($plan);
        $v = DB::transaction(function () use ($request, $p): PlanVersion {
            $next = (int) PlanVersion::query()->where('plan_id', $p->id)->lockForUpdate()->max('version') + 1;
            $v = new PlanVersion;
            $this->fillVersion($v, $request->validated() + ['plan_id' => $p->id, 'version' => $next, 'status' => 'draft', 'created_by' => $this->staff($request)]);

            return $v;
        });
        Audit::record('billing.plan_version_created', $v, ['plan' => $p->code, 'version' => $v->version], null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::version($v, $this->catalog->entitlements($v->id))], 201);
    }

    public function updateVersion(PlanVersionRequest $request, string $version): JsonResponse
    {
        $v = PlanVersion::query()->findOrFail($version);
        if ($v->status !== 'draft') {
            throw ApiException::conflict('version_immutable', __('billing.errors.version_immutable'));
        }
        DB::transaction(fn () => $this->fillVersion($v, $request->validated()));
        Audit::record('billing.plan_version_updated', $v, $request->validated(), null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::version($v, $this->catalog->entitlements($v->id))]);
    }

    public function publishVersion(Request $request, string $version): JsonResponse
    {
        $v = PlanVersion::query()->findOrFail($version);
        if ($v->status !== 'draft') {
            throw ApiException::conflict('version_immutable', __('billing.errors.version_immutable'));
        }
        if ($v->monthly_price_minor === null && $v->yearly_price_minor === null) {
            throw new ApiException(422, 'version_has_no_price', __('billing.errors.version_has_no_price'));
        }
        DB::transaction(function () use ($v): void {
            $p = Plan::query()->whereKey($v->plan_id)->lockForUpdate()->firstOrFail();
            PlanVersion::query()->where('plan_id', $p->id)->where('status', 'published')->update(['status' => 'retired', 'updated_at' => now()]);
            $v->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $p->forceFill(['current_version_id' => $v->id])->save();
        });
        // Existing subscriptions keep the version they bought (no retroactive price change).
        Audit::record('billing.plan_version_published', $v, ['version' => $v->version], null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::version($v->refresh(), $this->catalog->entitlements($v->id))]);
    }

    public function packages(): JsonResponse
    {
        return new JsonResponse(['data' => CreditPackage::query()->orderBy('display_order')->get()->map(fn ($p) => BillingPresenter::package($p, true))]);
    }

    public function storePackage(CreditPackageRequest $request): JsonResponse
    {
        $p = new CreditPackage;
        $p->forceFill($request->validated())->save();
        Audit::record('billing.credit_package_created', $p, $request->validated(), null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::package($p, true)], 201);
    }

    public function updatePackage(CreditPackageRequest $request, string $package): JsonResponse
    {
        $p = CreditPackage::query()->findOrFail($package);
        $data = $request->validated();
        unset($data['code']);
        // Orders keep their own snapshot; changing a package never alters a placed order.
        $p->forceFill($data)->save();
        Audit::record('billing.credit_package_updated', $p, $data, null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::package($p, true)]);
    }

    public function policies(): JsonResponse
    {
        $current = CreditPolicy::query()->where('effective_from', '<=', now())->orderByDesc('effective_from')->orderByDesc('version')->first();

        return new JsonResponse(['data' => [
            'current_id' => $current?->id,
            'policies' => CreditPolicy::query()->orderByDesc('version')->get()->map(fn ($p) => BillingPresenter::policy($p)),
        ]]);
    }

    public function storePolicy(CreditPolicyRequest $request): JsonResponse
    {
        $d = $request->validated();
        $p = DB::transaction(function () use ($d, $request): CreditPolicy {
            $p = new CreditPolicy;
            $p->forceFill([
                'version' => (int) CreditPolicy::query()->lockForUpdate()->max('version') + 1,
                'micro_usd_per_credit' => $d['micro_usd_per_credit'], 'markup_bps' => $d['markup_bps'], 'min_charge_mc' => $d['min_charge_mc'] ?? 0,
                // Never back-dated: a policy only applies to calls reserved after it takes effect.
                'effective_from' => isset($d['effective_from']) && now()->lt($d['effective_from']) ? $d['effective_from'] : now(),
                'notes' => $d['notes'] ?? null, 'created_by' => $this->staff($request),
            ])->save();

            return $p;
        });
        Audit::record('billing.credit_policy_created', $p, $d, null, $this->staff($request), 'staff');

        return new JsonResponse(['data' => BillingPresenter::policy($p)], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillVersion(PlanVersion $v, array $data): void
    {
        $ent = $data['entitlements'] ?? null;
        unset($data['entitlements']);
        $v->forceFill($data)->save();
        if (is_array($ent)) {
            PlanEntitlement::query()->where('plan_version_id', $v->id)->delete();
            foreach ($ent as $key => $limit) {
                if (! array_key_exists($key, Catalog::RESOURCES) || $limit === null || $limit === '') {
                    continue;
                }
                $e = new PlanEntitlement;
                $e->forceFill(['plan_version_id' => $v->id, 'resource_key' => $key, 'limit_value' => (int) $limit, 'period' => Catalog::RESOURCES[$key]])->save();
            }
        }
    }

    private function staff(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
