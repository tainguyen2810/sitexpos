<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Contracts\BillingReadiness;
use SiteXPost\Modules\Billing\Domain\CreditPolicy;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Payments\Adapters\AdapterRegistry;
use SiteXPost\Modules\Billing\Payments\VietQr;
use Throwable;

final class BillingReadinessReport implements BillingReadiness
{
    public function __construct(private readonly Catalog $catalog, private readonly BillingSettings $settings, private readonly AdapterRegistry $adapters) {}

    public function plans(): array
    {
        return Plan::query()->orderBy('display_order')->orderBy('code')->get()->map(function (Plan $p): array {
            $v = $this->catalog->currentVersion($p);

            return [
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status,
                'is_free' => $p->is_free,
                'is_demo' => $p->is_demo,
                'published' => $v !== null,
                'monthly_price_minor' => $v?->monthly_price_minor,
                'yearly_price_minor' => $v?->yearly_price_minor,
            ];
        })->values()->all();
    }

    public function createDraftPlans(array $codes, string $staffId): array
    {
        $created = [];
        DB::transaction(function () use ($codes, $staffId, &$created): void {
            $order = (int) Plan::query()->max('display_order');
            foreach ($codes as $code => $name) {
                if (Plan::query()->where('code', $code)->lockForUpdate()->exists()) {
                    continue; // never duplicate an existing plan
                }
                $plan = new Plan;
                $plan->forceFill(['code' => $code, 'name' => $name, 'status' => 'draft', 'is_free' => $code === 'free', 'display_order' => ++$order])->save();
                Audit::record('billing.plan_created', $plan, ['code' => $code, 'name' => $name, 'via' => 'setup_wizard'], null, $staffId, 'staff');
                $created[] = $code;
            }
        });

        return $created;
    }

    public function paymentProviders(): array
    {
        return PaymentProviderConfig::query()->orderBy('display_order')->get()->map(function (PaymentProviderConfig $c): array {
            $required = ['settings' => [], 'credentials' => []];
            try {
                $required = $this->adapters->get($c->adapter)->requiredConfig();
            } catch (Throwable) {
                // unknown adapter: report as not configured
            }
            $missing = [];
            foreach ($required['settings'] as $key) {
                if ((string) $c->setting($key, '') === '') {
                    $missing[] = $key;
                }
            }
            foreach ($required['credentials'] as $key) {
                if ($c->secret($key) === null) {
                    $missing[] = $key;
                }
            }
            $qrOk = null;
            $bin = (string) $c->setting('bank_bin', '');
            $account = (string) $c->setting('account_no', '');
            if ($bin !== '' && $account !== '') {
                try {
                    // Self-test of the QR encoder only — a QR is never proof of payment.
                    $payload = VietQr::payload($bin, $account, 10000, 'SXPTEST');
                    $qrOk = VietQr::crc16(substr($payload, 0, -4)) === substr($payload, -4);
                } catch (Throwable) {
                    $qrOk = false;
                }
            }

            return [
                'code' => $c->code,
                'name' => $c->name,
                'adapter' => $c->adapter,
                'enabled' => $c->is_enabled,
                'configured' => $missing === [],
                'missing' => $missing,
                'has_credentials' => $c->credentials !== null,
                'qr_ok' => $qrOk,
            ];
        })->values()->all();
    }

    public function creditPolicy(): array
    {
        $p = CreditPolicy::query()->where('effective_from', '<=', now())->orderByDesc('effective_from')->first();

        return [
            'configured' => $p !== null,
            'micro_usd_per_credit' => $p?->micro_usd_per_credit,
            'markup_bps' => $p?->markup_bps,
            'effective_from' => $p?->effective_from?->toIso8601ZuluString(),
        ];
    }

    public function settings(): array
    {
        return array_intersect_key($this->settings->all(), array_flip([
            'enforcement', 'default_plan_code', 'currency', 'trial_enabled', 'low_credit_threshold_mc', 'usd_to_currency_rate', 'grace_days', 'included_credits_expire',
        ]));
    }

    public function webhookHealth(DateTimeInterface $since): array
    {
        $q = fn () => DB::table('payment_webhook_events')->where('received_at', '>=', $since);
        $last = DB::table('payment_webhook_events')->max('received_at');

        return [
            'received' => $q()->count(),
            'processed' => $q()->where('status', 'processed')->count(),
            'rejected' => $q()->where('status', 'rejected')->count(),
            'last_received_at' => $last !== null ? (string) $last : null,
        ];
    }
}
