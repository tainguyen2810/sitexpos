<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Domain\CreditPolicy;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanEntitlement;
use SiteXPost\Modules\Billing\Domain\PlanVersion;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Payments\PaymentConfirmation;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Billing\Payments\Refunds;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionManager;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\Catalog;
use SiteXPost\Modules\Billing\Support\InTenant;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Actions\CreateWorkspace;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * DEVELOPMENT ONLY — Phase 7 billing sample data.
 *
 * Every plan / package is flagged `is_demo` and named "[MẪU]": the prices are
 * placeholders for the UI, NOT a price list. Everything goes through the real
 * services (orders → confirmation → ledger), so the demo ledger verifies.
 * Volumes: 4 plans, 50 subscriptions, 100 orders, ≥200 credit ledger
 * entries, 30 daily usage/cost reports, 10 refunds.
 */
class BillingDemoSeeder extends Seeder
{
    public function run(
        CreateWorkspace $createWorkspace,
        PaymentOrders $orders,
        PaymentConfirmation $confirmation,
        SubscriptionManager $subscriptions,
        CreditLedger $ledger,
        Refunds $refunds,
        BillingSettings $settings,
        Catalog $catalog,
    ): void {
        if (app()->isProduction()) {
            throw new RuntimeException('BillingDemoSeeder is disabled in production.');
        }
        if (Plan::query()->where('is_demo', true)->exists()) {
            return; // already seeded
        }

        $staff = User::query()->where('email', 'billing@'.DemoSeeder::DOMAIN)->first();
        $staffId = $staff?->id;

        // ---- catalog (sample) ------------------------------------------------
        $plans = [
            ['demo-free', '[MẪU] Miễn phí', 0, null, 20_000, true, ['websites' => 1, 'members' => 2, 'research_runs' => 2, 'keywords' => 100, 'articles' => 3, 'ai_images' => 5, 'publishing_jobs' => 3, 'storage_mb' => 200, 'automation_schedules' => 0], ['automation' => false, 'ai_images' => true], 'community'],
            ['demo-starter', '[MẪU] Khởi đầu', 199_000, 1_990_000, 200_000, false, ['websites' => 2, 'members' => 3, 'research_runs' => 10, 'keywords' => 1000, 'articles' => 30, 'ai_images' => 60, 'publishing_jobs' => 30, 'storage_mb' => 2000, 'automation_schedules' => 2], ['automation' => true], 'email'],
            ['demo-pro', '[MẪU] Chuyên nghiệp', 499_000, 4_990_000, 600_000, false, ['websites' => 5, 'members' => 10, 'research_runs' => 40, 'keywords' => 5000, 'articles' => 120, 'ai_images' => 250, 'publishing_jobs' => 120, 'storage_mb' => 10000, 'automation_schedules' => 10], ['automation' => true, 'auto_publish' => true], 'priority'],
            ['demo-agency', '[MẪU] Agency', 1_490_000, 14_900_000, 2_000_000, false, ['websites' => 25, 'members' => -1, 'research_runs' => -1, 'keywords' => -1, 'articles' => 500, 'ai_images' => 1000, 'publishing_jobs' => -1, 'storage_mb' => 50000, 'automation_schedules' => -1], ['automation' => true, 'auto_publish' => true, 'priority_support' => true], 'dedicated'],
        ];
        foreach ($plans as $i => [$code, $name, $monthly, $yearly, $included, $free, $limits, $features, $support]) {
            $plan = new Plan;
            $plan->forceFill(['code' => $code, 'name' => $name, 'description' => 'Gói mẫu cho môi trường phát triển — không phải bảng giá thật.', 'status' => 'active', 'is_free' => $free, 'is_demo' => true, 'display_order' => $i])->save();
            $v = new PlanVersion;
            $v->forceFill([
                'plan_id' => $plan->id, 'version' => 1, 'status' => 'published', 'currency' => 'VND', 'monthly_price_minor' => $monthly,
                'yearly_price_minor' => $yearly, 'trial_days' => $free ? 0 : 7, 'trial_credits_mc' => $free ? 0 : 50_000, 'included_credits_mc' => $included,
                'support_level' => $support, 'feature_flags' => $features, 'notes' => 'DỮ LIỆU MẪU', 'published_at' => now(),
            ])->save();
            foreach ($limits as $resource => $limit) {
                $e = new PlanEntitlement;
                $e->forceFill(['plan_version_id' => $v->id, 'resource_key' => $resource, 'limit_value' => $limit, 'period' => Catalog::RESOURCES[$resource]])->save();
            }
            $plan->forceFill(['current_version_id' => $v->id])->save();
        }

        foreach ([['demo-c100', '[MẪU] 100 credits', 100_000, 0, 99_000, 180], ['demo-c500', '[MẪU] 500 credits', 500_000, 50_000, 449_000, 365], ['demo-c2000', '[MẪU] 2000 credits', 2_000_000, 300_000, 1_590_000, null]] as $i => [$code, $name, $mc, $bonus, $price, $days]) {
            $p = new CreditPackage;
            $p->forceFill(['code' => $code, 'name' => $name, 'credits_mc' => $mc, 'bonus_credits_mc' => $bonus, 'price_minor' => $price, 'currency' => 'VND', 'validity_days' => $days, 'is_demo' => true, 'display_order' => $i])->save();
        }

        if (! CreditPolicy::query()->exists()) {
            $policy = new CreditPolicy;
            // Sample: 1 credit = 1 000 µUSD provider cost, +40 % markup, min 1 mc.
            $policy->forceFill(['version' => 1, 'micro_usd_per_credit' => 1000, 'markup_bps' => 4000, 'min_charge_mc' => 1, 'effective_from' => now()->subYear(), 'notes' => 'DỮ LIỆU MẪU'])->save();
        }

        if (! PaymentProviderConfig::query()->where('code', 'demo-bank')->exists()) {
            $bank = new PaymentProviderConfig;
            $bank->forceFill([
                'code' => 'demo-bank', 'adapter' => 'manual_bank', 'name' => 'Chuyển khoản (TÀI KHOẢN MẪU)', 'is_enabled' => true,
                'settings' => ['bank_name' => 'Ngân hàng mẫu', 'bank_bin' => '970436', 'account_no' => '0000000000', 'account_name' => 'SITEX POST DEMO'],
            ])->save();
        }
        $settings->update(['seller_name' => 'SiteX Post (DEMO)', 'usd_to_currency_rate' => 25_000], $staffId);

        // ---- 50 workspaces with subscriptions --------------------------------
        $workspaces = Workspace::query()->where('is_demo', true)->orderBy('created_at')->get()->all();
        for ($i = count($workspaces) + 1; count($workspaces) < 50; $i++) {
            $owner = User::query()->firstOrNew(['email' => sprintf('khach%02d@%s', $i, DemoSeeder::DOMAIN)]);
            $owner->fill(['name' => sprintf('Khách mẫu %02d', $i), 'password' => DemoSeeder::PASSWORD]);
            $owner->forceFill(['email_verified_at' => now()])->save();
            $workspaces[] = $createWorkspace->handle($owner, ['name' => sprintf('Khách mẫu %02d (Demo)', $i)], isDemo: true);
        }

        $cycle = ['demo-free', 'demo-starter', 'demo-pro', 'demo-starter', 'demo-agency', 'demo-pro', 'demo-free', 'demo-starter', 'demo-pro', 'demo-agency'];
        foreach ($workspaces as $i => $ws) {
            if (DB::table('subscriptions')->where('workspace_id', $ws->id)->exists()) {
                continue;
            }
            $code = $cycle[$i % count($cycle)];
            [$plan, $version] = $catalog->sellable($code);
            if ($plan->is_free) {
                $subscriptions->subscribeFree($ws->id, $plan, $version, null);

                continue;
            }
            $order = $orders->forSubscription($ws->id, $code, $i % 7 === 0 ? 'year' : 'month', 'demo-bank', 'demo-sub-'.$i, (string) $ws->owner_id);
            $confirmation->confirm($order->id, $order->amount_minor, null, 'manual', $staffId, 'DỮ LIỆU MẪU');
        }

        // ---- credit package orders up to 100 orders ---------------------------
        $packages = ['demo-c100', 'demo-c500', 'demo-c2000'];
        $creditOrders = [];
        for ($n = 0; DB::table('payment_orders')->count() < 100; $n++) {
            $ws = $workspaces[$n % count($workspaces)];
            $order = $orders->forCreditPackage($ws->id, $packages[$n % 3], 'demo-bank', 'demo-credit-'.$n, (string) $ws->owner_id);
            match (true) {
                $n % 10 === 7 => $orders->cancel($order, (string) $ws->owner_id),
                $n % 10 === 8 => $orders->markProcessing($order), // "I have transferred" — waiting for review
                $n % 10 === 9 => null,                            // still pending
                default => $confirmation->confirm($order->id, $order->amount_minor, null, 'manual', $staffId, 'DỮ LIỆU MẪU'),
            };
            if ($n % 10 < 7) {
                $creditOrders[] = $order;
            }
        }

        // ---- AI usage: consume credits through reservations -------------------
        $categories = ['research.discovery', 'writer.draft', 'writer.claims', 'media.image', 'seo.audit'];
        for ($k = 0; DB::table('credit_ledger_entries')->count() < 200; $k++) {
            $ws = $workspaces[$k % count($workspaces)];
            $key = 'demo-usage-'.$k;
            try {
                $ledger->reserve($ws->id, $key, $categories[$k % 5], null, 2_000 + ($k * 37) % 9_000);
                $ledger->settle($key, 1_500 + ($k * 53) % 8_000);
            } catch (\Throwable) {
                // wallet empty for this sample tenant: skip
            }
            if ($k > 2000) {
                break;
            }
        }

        // ---- 10 refunds (8 succeeded, 2 failed) -------------------------------
        foreach (array_slice($creditOrders, 0, 10) as $j => $order) {
            $order->refresh();
            $amount = $j % 2 === 0 ? $order->paid_amount_minor : intdiv((int) $order->paid_amount_minor, 2);
            $refund = $refunds->request($order, (int) $amount, 'DỮ LIỆU MẪU — khách yêu cầu hoàn tiền', (string) $staffId);
            $refunds->complete($refund, $j < 8, $j < 8 ? 'DEMO-RF-'.($j + 1) : null, $j < 8 ? null : 'DỮ LIỆU MẪU — sai số tài khoản', (string) $staffId);
        }

        // ---- 30 daily cost reports (provider "demo", never mixed with real days) ---
        $first = $workspaces[0];
        for ($d = 3; $d < 33; $d++) {
            DB::table('cost_records')->insertOrIgnore([
                'workspace_id' => $first->id, 'day' => now()->subDays($d)->toDateString(), 'category' => ['research', 'writing', 'fact_check', 'image'][$d % 4],
                'provider' => 'demo', 'model' => 'demo-sample', 'requests' => 5 + $d, 'cost_micro_usd' => 20_000 + $d * 1_700, 'uncertain_micro_usd' => $d % 9 === 0 ? 1_500 : 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // A few lifecycle states for the admin screens.
        $live = DB::table('subscriptions')->whereNotNull('live_key')->where('price_minor', '>', 0)->orderBy('created_at')->limit(3)->get(['id', 'workspace_id']);
        if ($live->count() === 3 && $staffId !== null) {
            foreach ($live as $idx => $row) {
                InTenant::run((string) $row->workspace_id, function () use ($row, $idx, $subscriptions, $staffId): void {
                    $sub = Subscription::query()->findOrFail($row->id);
                    match ($idx) {
                        0 => $subscriptions->cancel($sub, null, 'DỮ LIỆU MẪU'),
                        1 => $subscriptions->staffAction($sub, 'suspend', 'DỮ LIỆU MẪU — kiểm tra thanh toán', null, $staffId),
                        default => $subscriptions->staffAction($sub, 'extend', 'DỮ LIỆU MẪU — bồi thường', 7, $staffId),
                    };
                });
            }
        }

        $this->command?->info('Billing demo: '.DB::table('subscriptions')->count().' subscriptions, '
            .DB::table('payment_orders')->count().' orders, '.DB::table('credit_ledger_entries')->count().' ledger entries, '
            .DB::table('refunds')->count().' refunds (DỮ LIỆU MẪU).');
    }
}
