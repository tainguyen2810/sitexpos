<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\SubscriptionChange;
use SiteXPost\Modules\Billing\Payments\Adapters\AdapterRegistry;
use SiteXPost\Modules\Billing\Subscriptions\SubscriptionStatus;
use SiteXPost\Modules\Billing\Support\BillingNotifier;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\Catalog;
use SiteXPost\Modules\Billing\Support\InTenant;

/**
 * Payment orders. Creating or viewing an order never activates anything:
 * only PaymentConfirmation (verified transaction or staff review) does.
 * Amounts always come from the catalog / quote on the server, never from
 * the request.
 */
final class PaymentOrders
{
    public const STATUSES = ['PENDING', 'PROCESSING', 'PAID', 'EXPIRED', 'CANCELLED', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED'];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'PENDING' => ['PROCESSING', 'PAID', 'EXPIRED', 'CANCELLED', 'FAILED'],
        'PROCESSING' => ['PAID', 'EXPIRED', 'CANCELLED', 'FAILED'],
        'EXPIRED' => ['PAID'], // only by staff approving a late, verified transfer
        'FAILED' => ['PAID'],
        'PAID' => ['REFUNDED', 'PARTIALLY_REFUNDED'],
        'PARTIALLY_REFUNDED' => ['PARTIALLY_REFUNDED', 'REFUNDED'],
        'CANCELLED' => [],
        'REFUNDED' => [],
    ];

    private const REF_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Catalog $catalog,
        private readonly BillingSettings $settings,
        private readonly AdapterRegistry $adapters,
        private readonly BillingNotifier $notifier,
    ) {}

    public function forSubscription(string $workspaceId, string $planCode, string $interval, ?string $provider, ?string $idempotencyKey, ?string $actorId): PaymentOrder
    {
        [$plan, $version] = $this->catalog->sellable($planCode);
        $price = Catalog::price($version, $interval) ?? throw new ApiException(422, 'interval_not_sold', __('billing.errors.interval_not_sold'));
        if ($price <= 0) {
            throw new ApiException(422, 'free_plan_no_payment', __('billing.errors.free_plan_no_payment'));
        }
        $live = InTenant::run($workspaceId, fn (): ?Subscription => Subscription::query()->where('live_key', $workspaceId)->first());
        if ($live !== null && $live->price_minor > 0 && $live->status !== SubscriptionStatus::Trialing->value) {
            throw ApiException::conflict('already_subscribed', __('billing.errors.use_change_or_renew'));
        }

        return $this->create($workspaceId, [
            'purpose' => 'subscription', 'plan_version_id' => $version->id, 'interval' => $interval,
            'description' => __('billing.order.subscription', ['plan' => $plan->name, 'interval' => __('billing.interval.'.$interval)]),
            'amount_minor' => $price, 'currency' => $version->currency,
            'snapshot' => ['plan_code' => $plan->code, 'plan_name' => $plan->name, 'version' => $version->version, 'interval' => $interval, 'price_minor' => $price],
        ], $provider, $idempotencyKey, $actorId);
    }

    public function forRenewal(string $workspaceId, ?string $provider, ?string $idempotencyKey, ?string $actorId): PaymentOrder
    {
        $sub = InTenant::run($workspaceId, fn (): ?Subscription => Subscription::query()->where('live_key', $workspaceId)->first())
            ?? throw new ApiException(422, 'no_subscription', __('billing.errors.no_subscription'));
        if (! in_array($sub->status, ['ACTIVE', 'CANCELLED', 'PAST_DUE', 'GRACE_PERIOD'], true)) {
            throw new ApiException(422, 'cannot_renew', __('billing.errors.cannot_renew'));
        }
        // Existing open renewal order = idempotent answer (no second QR for the same period).
        $open = InTenant::run($workspaceId, fn (): ?PaymentOrder => PaymentOrder::query()->where('subscription_id', $sub->id)->where('purpose', 'renewal')
            ->whereIn('status', ['PENDING', 'PROCESSING'])->where('expires_at', '>', now())->first());
        if ($open !== null) {
            return $open;
        }
        $scheduled = InTenant::run($workspaceId, fn (): ?SubscriptionChange => SubscriptionChange::query()->where('subscription_id', $sub->id)->where('status', 'scheduled')->first());
        $version = $this->catalog->version($scheduled !== null ? $scheduled->to_version_id : $sub->plan_version_id);
        $interval = $scheduled !== null ? $scheduled->to_interval : $sub->interval;
        $plan = $this->catalog->plan($version->plan_id);
        $price = Catalog::price($version, $interval);
        if ($price === null || $price <= 0) {
            throw new ApiException(422, 'free_plan_no_payment', __('billing.errors.free_plan_no_payment'));
        }

        return $this->create($workspaceId, [
            'purpose' => 'renewal', 'plan_version_id' => $version->id, 'interval' => $interval, 'subscription_id' => $sub->id,
            'description' => __('billing.order.renewal', ['plan' => $plan->name, 'interval' => __('billing.interval.'.$interval)]),
            'amount_minor' => $price, 'currency' => $version->currency,
            'snapshot' => ['plan_code' => $plan->code, 'plan_name' => $plan->name, 'version' => $version->version, 'interval' => $interval, 'price_minor' => $price,
                'current_period_end' => $sub->current_period_end?->toIso8601ZuluString()],
        ], $provider, $idempotencyKey, $actorId);
    }

    public function forChange(SubscriptionChange $change, ?string $provider, ?string $actorId): PaymentOrder
    {
        $version = $this->catalog->version($change->to_version_id);
        $plan = $this->catalog->plan($version->plan_id);

        $order = $this->create($change->workspace_id, [
            'purpose' => 'upgrade', 'plan_version_id' => $version->id, 'interval' => $change->to_interval,
            'subscription_id' => $change->subscription_id, 'subscription_change_id' => $change->id,
            'description' => __('billing.order.upgrade', ['plan' => $plan->name]),
            'amount_minor' => $change->amount_minor, 'currency' => $change->currency,
            'snapshot' => ['plan_code' => $plan->code, 'plan_name' => $plan->name, 'version' => $version->version, 'quote' => $change->quote],
        ], $provider, 'change:'.$change->id, $actorId);
        InTenant::run($change->workspace_id, fn () => $change->forceFill(['payment_order_id' => $order->id])->save());

        return $order;
    }

    public function forCreditPackage(string $workspaceId, string $code, ?string $provider, ?string $idempotencyKey, ?string $actorId): PaymentOrder
    {
        $pkg = CreditPackage::query()->where('code', $code)->where('status', 'active')->first() ?? throw ApiException::notFound();

        return $this->create($workspaceId, [
            'purpose' => 'credit_package', 'credit_package_id' => $pkg->id,
            'description' => __('billing.order.credits', ['name' => $pkg->name]),
            'amount_minor' => $pkg->price_minor, 'currency' => $pkg->currency,
            'snapshot' => ['code' => $pkg->code, 'name' => $pkg->name, 'credits_mc' => $pkg->credits_mc, 'bonus_credits_mc' => $pkg->bonus_credits_mc,
                'validity_days' => $pkg->validity_days, 'price_minor' => $pkg->price_minor],
        ], $provider, $idempotencyKey, $actorId);
    }

    /** Customer says "I have transferred": informational only, never activates. */
    public function markProcessing(PaymentOrder $order): PaymentOrder
    {
        return $this->transition($order, 'PROCESSING', null);
    }

    public function cancel(PaymentOrder $order, ?string $actorId): PaymentOrder
    {
        $order = $this->transition($order, 'CANCELLED', ['cancelled_at' => now()]);
        Audit::record('billing.order_cancelled', $order, [], $order->workspace_id, $actorId);

        return $order;
    }

    /**
     * Scheduler: PENDING/PROCESSING orders past their expiry → EXPIRED. A
     * transfer that arrives later goes to the manual review queue.
     */
    public function expireDue(): int
    {
        $n = 0;
        DB::table('payment_orders')->whereIn('status', ['PENDING', 'PROCESSING'])->where('expires_at', '<=', now())
            ->limit(1000)->get(['id', 'workspace_id'])->each(function (object $row) use (&$n): void {
                InTenant::run((string) $row->workspace_id, function () use ($row, &$n): void {
                    $order = PaymentOrder::query()->find($row->id);
                    if ($order === null || ! in_array($order->status, ['PENDING', 'PROCESSING'], true)) {
                        return;
                    }
                    $this->transition($order, 'EXPIRED', null);
                    $this->notifier->send($order->workspace_id, 'payment_expired', 'order_expired:'.$order->id, ['reference' => $order->reference]);
                    $n++;
                });
            });

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function instructions(PaymentOrder $order): array
    {
        $config = PaymentProviderConfig::query()->where('code', $order->provider)->first();
        if ($config === null) {
            return ['type' => 'unavailable', 'configured' => false];
        }

        return $this->adapters->get($config->adapter)->instructions($order, $config) + ['provider_name' => $config->name];
    }

    /**
     * @param  array<string, mixed>|null  $extra
     */
    public function transition(PaymentOrder $order, string $to, ?array $extra): PaymentOrder
    {
        return InTenant::run($order->workspace_id, fn (): PaymentOrder => DB::transaction(function () use ($order, $to, $extra): PaymentOrder {
            $o = PaymentOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($o->status === $to && $to !== 'PARTIALLY_REFUNDED') {
                return $o;
            }
            if (! in_array($to, self::TRANSITIONS[$o->status] ?? [], true)) {
                throw ApiException::conflict('invalid_order_transition', __('billing.errors.invalid_order_transition', ['from' => $o->status, 'to' => $to]));
            }
            $o->forceFill(['status' => $to] + ($extra ?? []))->save();

            return $o;
        }));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function create(string $workspaceId, array $attrs, ?string $provider, ?string $idempotencyKey, ?string $actorId): PaymentOrder
    {
        $config = $this->provider($provider);

        return InTenant::run($workspaceId, function () use ($attrs, $config, $idempotencyKey, $actorId): PaymentOrder {
            if ($idempotencyKey !== null) {
                $existing = PaymentOrder::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            for ($try = 0; $try < 5; $try++) {
                try {
                    $order = new PaymentOrder;
                    $order->forceFill($attrs + [
                        'provider' => $config->code, 'reference' => self::newReference(), 'status' => 'PENDING',
                        'idempotency_key' => $idempotencyKey, 'created_by' => $actorId,
                        'expires_at' => now()->addMinutes(max(5, $this->settings->int('order_expiry_minutes'))),
                    ])->save();
                    Audit::record('billing.order_created', $order, ['purpose' => $order->purpose, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency]);

                    return $order;
                } catch (QueryException $e) {
                    if ($idempotencyKey !== null && ($existing = PaymentOrder::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                        return $existing; // concurrent duplicate submit
                    }
                    if ($try === 4) {
                        throw $e;
                    }
                }
            }
            throw new \RuntimeException('unreachable');
        });
    }

    private function provider(?string $code): PaymentProviderConfig
    {
        $q = PaymentProviderConfig::query()->where('is_enabled', true);
        $config = $code !== null ? $q->where('code', $code)->first() : $q->orderBy('display_order')->first();

        return $config ?? throw new ApiException(422, 'no_payment_provider', __('billing.errors.no_payment_provider'));
    }

    public static function newReference(): string
    {
        $ref = 'SXP';
        for ($i = 0; $i < 8; $i++) {
            $ref .= self::REF_ALPHABET[random_int(0, strlen(self::REF_ALPHABET) - 1)];
        }

        return $ref;
    }
}
