<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

use SiteXPost\Modules\Billing\Domain\BillingSetting;

/**
 * Global billing policy (Super Admin → Billing Settings). Every value has a
 * conservative default; nothing about prices lives here (prices are plan
 * versions / credit packages / credit policies created by staff).
 */
final class BillingSettings
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        // off: plans are not enforced yet (no catalog configured) — modules keep
        // their Phase 4–6 interim limits. enforce: every workspace needs a live
        // subscription or the default plan; AI requires credits.
        'enforcement' => 'off',
        'default_plan_code' => null, // e.g. a free plan given to workspaces without subscription
        'currency' => 'VND',
        'trial_enabled' => false,
        'trial_requires_verified_email' => true,
        'grace_days' => 3,
        'grace_allows_ai' => false,
        'proration_enabled' => false,
        'order_expiry_minutes' => 60 * 24,
        'renewal_notice_days' => [7, 3, 1],
        'low_credit_threshold_mc' => 50_000,
        'overpayment_policy' => 'manual_review', // manual_review | accept_and_credit_difference
        'included_credits_expire' => true,
        'allow_non_expiring_purchased_credits' => true,
        'reservation_ttl_minutes' => 30,
        'usd_to_currency_rate' => null, // integer, e.g. 25000 VND per USD — needed for profit in local currency
        'loss_alert_margin_bps' => 0, // alert when gross margin falls below this (0 = loss-making)
        'heavy_user_cost_share_bps' => 2000, // tenant above 20 % of total AI cost = heavy user
        'seller_name' => null,
        'seller_tax_code' => null,
        'seller_address' => null,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : null;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function enforcing(): bool
    {
        return $this->get('enforcement') === 'enforce';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = BillingSetting::query()->get()->mapWithKeys(fn (BillingSetting $s) => [$s->key => $s->value['v'] ?? null])->all();
            $this->cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        }

        return $this->cache;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values, ?string $userId): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            $row = BillingSetting::query()->find($key) ?? new BillingSetting;
            $row->forceFill(['key' => $key, 'value' => ['v' => $value], 'updated_by' => $userId])->save();
        }
        $this->cache = null;
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
