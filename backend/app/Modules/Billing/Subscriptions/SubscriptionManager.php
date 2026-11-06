<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Subscriptions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanVersion;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\SubscriptionChange;
use SiteXPost\Modules\Billing\Domain\SubscriptionEvent;
use SiteXPost\Modules\Billing\Domain\TrialRedemption;
use SiteXPost\Modules\Billing\Support\BillingNotifier;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\Catalog;
use SiteXPost\Modules\Billing\Support\InTenant;
use SiteXPost\Modules\Identity\Domain\User;

/**
 * Subscription lifecycle (SUBSCRIPTION-LIFECYCLE.md). Every method runs in a
 * transaction holding the subscription row lock; every status change goes
 * through `transition()` (allowed-transition check + subscription_events).
 * Expiry never deletes data: it only stops new paid work.
 */
final class SubscriptionManager
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly Catalog $catalog,
        private readonly CreditLedger $credits,
        private readonly BillingNotifier $notifier,
    ) {}

    public function live(string $workspaceId): ?Subscription
    {
        return InTenant::run($workspaceId, fn (): ?Subscription => Subscription::query()->where('live_key', $workspaceId)->first());
    }

    // ------------------------------------------------------------------ trial

    /**
     * @return array{eligible: bool, reason: string|null}
     */
    public function trialEligibility(string $workspaceId, User $owner, PlanVersion $version): array
    {
        if (! $this->settings->bool('trial_enabled')) {
            return ['eligible' => false, 'reason' => 'trial_disabled'];
        }
        if ($version->trial_days <= 0) {
            return ['eligible' => false, 'reason' => 'plan_has_no_trial'];
        }
        if ($this->settings->bool('trial_requires_verified_email') && $owner->email_verified_at === null) {
            return ['eligible' => false, 'reason' => 'email_not_verified'];
        }
        $used = TrialRedemption::query()->where('user_id', $owner->id)->orWhere('email_hash', self::emailHash((string) $owner->email))->exists();
        if ($used) {
            return ['eligible' => false, 'reason' => 'trial_already_used'];
        }
        $live = $this->live($workspaceId);
        if ($live !== null && ! $this->isFreeSub($live)) {
            return ['eligible' => false, 'reason' => 'already_subscribed'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function startTrial(string $workspaceId, User $owner, Plan $plan, PlanVersion $version, ?string $actorId): Subscription
    {
        $check = $this->trialEligibility($workspaceId, $owner, $version);
        if (! $check['eligible']) {
            throw new ApiException(422, 'trial_not_eligible', __('billing.errors.trial_'.$check['reason']), ['reason' => $check['reason']]);
        }

        return InTenant::run($workspaceId, fn (): Subscription => DB::transaction(function () use ($workspaceId, $owner, $plan, $version, $actorId): Subscription {
            $redemption = new TrialRedemption;
            $redemption->forceFill([
                'user_id' => $owner->id, 'email_hash' => self::emailHash((string) $owner->email),
                'workspace_id' => $workspaceId, 'plan_id' => $plan->id, 'created_at' => now(),
            ])->save(); // unique indexes = hard stop for concurrent second trials

            $this->endFreeSubscription($workspaceId, 'replaced_by_trial', $actorId);
            $now = CarbonImmutable::now();
            $end = $now->addDays($version->trial_days);
            $sub = $this->create($plan, $version, SubscriptionStatus::Trialing, 'month', 0, $now, $end, ['trial_ends_at' => $end]);
            $this->event($sub, 'trial_started', null, SubscriptionStatus::Trialing, $actorId, null, ['trial_days' => $version->trial_days]);
            if ($version->trial_credits_mc > 0) {
                $this->credits->grant($workspaceId, 'trial', $version->trial_credits_mc, $end, 'trial:'.$sub->id, description: 'trial credits', referenceType: 'subscription', referenceId: $sub->id);
            }
            Audit::record('billing.trial_started', $sub, ['plan' => $plan->code, 'version' => $version->version]);

            return $sub;
        }));
    }

    // ------------------------------------------------------------------ free plan

    public function subscribeFree(string $workspaceId, Plan $plan, PlanVersion $version, ?string $actorId): Subscription
    {
        if (! Catalog::isFree($plan, $version)) {
            throw new ApiException(422, 'payment_required', __('billing.errors.payment_required'));
        }

        return InTenant::run($workspaceId, fn (): Subscription => DB::transaction(function () use ($workspaceId, $plan, $version, $actorId): Subscription {
            $live = Subscription::query()->where('live_key', $workspaceId)->lockForUpdate()->first();
            if ($live !== null) {
                throw ApiException::conflict('already_subscribed', __('billing.errors.already_subscribed'));
            }
            $now = CarbonImmutable::now();
            $sub = $this->create($plan, $version, SubscriptionStatus::Active, 'month', 0, $now, $now->addMonth());
            $this->event($sub, 'activated', null, SubscriptionStatus::Active, $actorId, null, ['free' => true]);
            $this->grantIncluded($sub, $version);
            Audit::record('billing.subscribed', $sub, ['plan' => $plan->code, 'free' => true]);

            return $sub;
        }));
    }

    // ------------------------------------------------------------------ paid fulfilment

    /**
     * Apply a PAID order (called once per order by PaymentConfirmation inside
     * its transaction; the order row lock + fulfilled_at make it idempotent).
     */
    public function fulfil(PaymentOrder $order): Subscription
    {
        $version = $this->catalog->version((string) $order->plan_version_id);
        $plan = $this->catalog->plan($version->plan_id);
        $interval = (string) ($order->interval ?? 'month');
        $now = CarbonImmutable::now();
        $live = Subscription::query()->where('live_key', $order->workspace_id)->lockForUpdate()->first();

        if ($order->purpose === 'upgrade' && $live !== null && $order->subscription_change_id !== null) {
            $change = SubscriptionChange::query()->whereKey($order->subscription_change_id)->lockForUpdate()->firstOrFail();

            return $this->applyChange($live, $change, $order);
        }

        if ($order->purpose === 'renewal' && $live !== null) {
            return $this->renew($live, $version, $interval, (int) $order->amount_minor, $order);
        }

        // New subscription, trial conversion, or re-subscribe after expiry.
        if ($live !== null && $live->status === SubscriptionStatus::Trialing->value) {
            $from = SubscriptionStatus::Trialing;
            $live->forceFill([
                'plan_id' => $plan->id, 'plan_version_id' => $version->id, 'interval' => $interval, 'price_minor' => $order->amount_minor,
                'currency' => $order->currency, 'current_period_start' => $now, 'current_period_end' => self::addInterval($now, $interval), 'trial_ends_at' => null,
            ]);
            $this->transition($live, SubscriptionStatus::Active, 'trial_converted', null, null, ['order' => $order->id], $from, $order->id);
            $this->grantIncluded($live, $version);

            return $live;
        }
        if ($live !== null && $this->isFreeSub($live)) {
            $this->transition($live, SubscriptionStatus::Expired, 'replaced', null, 'upgraded to a paid plan', ['order' => $order->id]);
            $live = null;
        }
        if ($live !== null) {
            // Already on a paid plan (e.g. a stale second checkout): treat as renewal of the bought version.
            return $this->renew($live, $version, $interval, (int) $order->amount_minor, $order);
        }

        $sub = $this->create($plan, $version, SubscriptionStatus::Active, $interval, (int) $order->amount_minor, $now, self::addInterval($now, $interval));
        $this->event($sub, 'activated', null, SubscriptionStatus::Active, null, null, ['order' => $order->id], $order->id);
        $this->grantIncluded($sub, $version);

        return $sub;
    }

    private function renew(Subscription $sub, PlanVersion $version, string $interval, int $price, PaymentOrder $order): Subscription
    {
        $status = SubscriptionStatus::from($sub->status);
        $now = CarbonImmutable::now();
        $end = $sub->current_period_end !== null ? CarbonImmutable::instance($sub->current_period_end) : $now;
        // Expired → new period from today; otherwise continue from the old end (no gap, no double period).
        $start = $status === SubscriptionStatus::Expired || $end->lessThan($now->subYear()) ? $now : $end;
        if ($status === SubscriptionStatus::Expired) {
            throw ApiException::conflict('subscription_expired', __('billing.errors.subscription_expired'));
        }

        // A scheduled downgrade/interval change takes effect with the renewal it was priced into.
        $scheduled = SubscriptionChange::query()->where('subscription_id', $sub->id)->where('status', 'scheduled')->lockForUpdate()->first();
        if ($scheduled !== null && $scheduled->to_version_id === $version->id) {
            $scheduled->forceFill(['status' => 'applied', 'applied_at' => now(), 'payment_order_id' => $order->id])->save();
            $this->event($sub, 'plan_changed', null, null, null, null, ['change' => $scheduled->id, 'kind' => $scheduled->kind], $order->id);
        }

        $sub->forceFill([
            'plan_id' => $version->plan_id, 'plan_version_id' => $version->id, 'interval' => $interval, 'price_minor' => $price,
            'current_period_start' => $start, 'current_period_end' => self::addInterval($start, $interval),
            'grace_ends_at' => null, 'cancelled_at' => null,
        ]);
        $this->transition($sub, SubscriptionStatus::Active, 'renewed', null, null, ['order' => $order->id, 'period_end' => self::addInterval($start, $interval)->toIso8601ZuluString()], $status, $order->id);
        $this->grantIncluded($sub, $version);

        return $sub;
    }

    // ------------------------------------------------------------------ plan changes

    /**
     * Price an upgrade/downgrade BEFORE the customer confirms.
     *
     * @return array{kind: string, amount_minor: int, currency: string, effective: string, effective_at: string|null, new_period_end: string|null, proration: array<string, int>|null, credits_policy: string}
     */
    public function quote(Subscription $sub, PlanVersion $target, string $interval): array
    {
        $current = $this->catalog->version($sub->plan_version_id);
        $price = Catalog::price($target, $interval) ?? throw new ApiException(422, 'interval_not_sold', __('billing.errors.interval_not_sold'));
        if ($target->id === $current->id && $interval === $sub->interval) {
            throw new ApiException(422, 'same_plan', __('billing.errors.same_plan'));
        }
        $isUpgrade = Catalog::monthlyEquivalent($target, $interval) > Catalog::monthlyEquivalent($current, $sub->interval)
            || ($sub->interval === 'month' && $interval === 'year' && $price > 0);

        if (! $isUpgrade || SubscriptionStatus::from($sub->status) === SubscriptionStatus::Trialing && $price === 0) {
            return [
                'kind' => 'downgrade', 'amount_minor' => 0, 'currency' => $target->currency, 'effective' => 'period_end',
                'effective_at' => $sub->current_period_end?->toIso8601ZuluString(), 'new_period_end' => null, 'proration' => null,
                'credits_policy' => 'current_plan_credits_kept_until_expiry',
            ];
        }

        $now = CarbonImmutable::now();
        $prorate = $this->settings->bool('proration_enabled') && $interval === $sub->interval
            && $sub->status === SubscriptionStatus::Active->value && $sub->current_period_end !== null && $sub->current_period_end->isFuture();
        if ($prorate) {
            $p = Proration::upgrade($sub->price_minor, $price, $sub->current_period_start, $sub->current_period_end, $now);

            return [
                'kind' => 'upgrade', 'amount_minor' => $p['charge_minor'], 'currency' => $target->currency, 'effective' => 'immediate',
                'effective_at' => $now->toIso8601ZuluString(), 'new_period_end' => $sub->current_period_end->toIso8601ZuluString(),
                'proration' => $p, 'credits_policy' => 'prorated_included_credit_difference',
            ];
        }

        return [
            'kind' => 'upgrade', 'amount_minor' => $price, 'currency' => $target->currency, 'effective' => 'immediate',
            'effective_at' => $now->toIso8601ZuluString(), 'new_period_end' => self::addInterval($now, $interval)->toIso8601ZuluString(),
            'proration' => null, 'credits_policy' => 'new_period_full_included_credits_existing_kept',
        ];
    }

    /**
     * Customer confirmed a change. Upgrade → pending change (+ order created by
     * the caller when amount > 0); downgrade → scheduled for the period end.
     */
    public function requestChange(Subscription $sub, PlanVersion $target, string $interval, ?string $actorId): SubscriptionChange
    {
        $quote = $this->quote($sub, $target, $interval);

        return InTenant::run($sub->workspace_id, fn (): SubscriptionChange => DB::transaction(function () use ($sub, $target, $interval, $actorId, $quote): SubscriptionChange {
            $locked = Subscription::query()->whereKey($sub->id)->lockForUpdate()->firstOrFail();
            // Only one open change at a time.
            SubscriptionChange::query()->where('subscription_id', $locked->id)->whereIn('status', ['pending_payment', 'scheduled'])
                ->get()->each(fn (SubscriptionChange $c) => $c->forceFill(['status' => 'cancelled'])->save());

            $change = new SubscriptionChange;
            $change->forceFill([
                'subscription_id' => $locked->id, 'kind' => $quote['kind'], 'from_version_id' => $locked->plan_version_id,
                'to_version_id' => $target->id, 'to_interval' => $interval,
                'status' => $quote['kind'] === 'upgrade' ? 'pending_payment' : 'scheduled',
                'effective_at' => $quote['kind'] === 'upgrade' ? now() : $locked->current_period_end,
                'amount_minor' => $quote['amount_minor'], 'currency' => $quote['currency'], 'quote' => $quote, 'requested_by' => $actorId,
            ])->save();
            $this->event($locked, $quote['kind'] === 'upgrade' ? 'upgrade_requested' : 'downgrade_scheduled', null, null, $actorId, null, ['change' => $change->id, 'to_version' => $target->id, 'interval' => $interval]);
            if ($quote['kind'] === 'upgrade' && $quote['amount_minor'] === 0) {
                $this->applyChange($locked, $change, null);
            }
            Audit::record('billing.plan_change_requested', $locked, ['kind' => $quote['kind'], 'to_version' => $target->id, 'amount_minor' => $quote['amount_minor']]);

            return $change->refresh();
        }));
    }

    public function cancelScheduledChange(Subscription $sub, ?string $actorId): void
    {
        InTenant::run($sub->workspace_id, fn () => DB::transaction(function () use ($sub, $actorId): void {
            $n = 0;
            SubscriptionChange::query()->where('subscription_id', $sub->id)->whereIn('status', ['scheduled', 'pending_payment'])->lockForUpdate()->get()
                ->each(function (SubscriptionChange $c) use (&$n): void {
                    $c->forceFill(['status' => 'cancelled'])->save();
                    $n++;
                });
            if ($n > 0) {
                $this->event($sub, 'change_cancelled', null, null, $actorId, null, null);
            }
        }));
    }

    private function applyChange(Subscription $sub, SubscriptionChange $change, ?PaymentOrder $order): Subscription
    {
        if ($change->status === 'applied') {
            return $sub;
        }
        $from = $this->catalog->version($change->from_version_id);
        $to = $this->catalog->version($change->to_version_id);
        $now = CarbonImmutable::now();
        $prorated = is_array($change->quote) && ($change->quote['proration'] ?? null) !== null;
        $price = (int) Catalog::price($to, $change->to_interval);

        if ($prorated && $sub->current_period_end !== null) {
            $ratio = $change->quote['proration'];
            $extra = max(0, $to->included_credits_mc - $from->included_credits_mc);
            $grant = intdiv($extra * (int) $ratio['remaining_seconds'], max(1, (int) $ratio['total_seconds']));
            $sub->forceFill(['plan_id' => $to->plan_id, 'plan_version_id' => $to->id, 'price_minor' => $price]);
            if ($grant > 0) {
                $this->credits->grant($sub->workspace_id, 'included', $grant, $this->includedExpiry($sub), 'change:'.$change->id, description: 'upgrade credits (prorated)', referenceType: 'subscription', referenceId: $sub->id);
            }
        } else {
            $sub->forceFill([
                'plan_id' => $to->plan_id, 'plan_version_id' => $to->id, 'interval' => $change->to_interval, 'price_minor' => $price,
                'current_period_start' => $now, 'current_period_end' => self::addInterval($now, $change->to_interval),
            ]);
        }
        $change->forceFill(['status' => 'applied', 'applied_at' => now(), 'payment_order_id' => $order?->id])->save();
        $prev = SubscriptionStatus::from($sub->status);
        $this->transition($sub, SubscriptionStatus::Active, 'upgraded', null, null, ['change' => $change->id, 'from_version' => $from->id, 'to_version' => $to->id], $prev, $order?->id);
        if (! $prorated) {
            $this->grantIncluded($sub, $to);
        }
        $this->notifier->send($sub->workspace_id, 'plan_changed', 'plan_changed:'.$change->id, ['plan' => $to->plan_id]);

        return $sub;
    }

    // ------------------------------------------------------------------ customer & staff actions

    public function cancel(Subscription $sub, ?string $actorId, ?string $reason, string $actorType = 'user'): Subscription
    {
        return $this->locked($sub, function (Subscription $s) use ($actorId, $reason, $actorType): Subscription {
            if ($this->isFreeSub($s)) {
                $s->forceFill(['ended_at' => now()]);
                $this->transition($s, SubscriptionStatus::Expired, 'cancelled', $actorId, $reason, null, null, null, $actorType);
            } else {
                $s->forceFill(['cancelled_at' => now()]);
                $this->transition($s, SubscriptionStatus::Cancelled, 'cancelled', $actorId, $reason, ['access_until' => $s->current_period_end?->toIso8601ZuluString()], null, null, $actorType);
            }
            SubscriptionChange::query()->where('subscription_id', $s->id)->whereIn('status', ['scheduled', 'pending_payment'])->get()
                ->each(fn (SubscriptionChange $c) => $c->forceFill(['status' => 'cancelled'])->save());
            Audit::record('billing.subscription_cancelled', $s, ['reason' => $reason]);

            return $s;
        });
    }

    public function resume(Subscription $sub, ?string $actorId, string $actorType = 'user'): Subscription
    {
        return $this->locked($sub, function (Subscription $s) use ($actorId, $actorType): Subscription {
            $status = SubscriptionStatus::from($s->status);
            if ($status === SubscriptionStatus::Cancelled && ($s->current_period_end === null || $s->current_period_end->isFuture())) {
                $s->forceFill(['cancelled_at' => null]);
                $this->transition($s, SubscriptionStatus::Active, 'resumed', $actorId, null, null, null, null, $actorType);
            } elseif ($status === SubscriptionStatus::Paused && $actorType === 'staff') {
                $s->forceFill(['status_before_hold' => null]);
                $this->transition($s, SubscriptionStatus::Active, 'resumed', $actorId, null, null, null, null, $actorType);
            } else {
                throw new ApiException(422, 'cannot_resume', __('billing.errors.cannot_resume'));
            }
            Audit::record('billing.subscription_resumed', $s);

            return $s;
        });
    }

    /** Staff: pause / suspend / unsuspend / extend / change status with a reason. */
    public function staffAction(Subscription $sub, string $action, string $reason, ?int $days, string $staffId): Subscription
    {
        return $this->locked($sub, function (Subscription $s) use ($action, $reason, $days, $staffId): Subscription {
            $status = SubscriptionStatus::from($s->status);
            switch ($action) {
                case 'pause':
                    $s->forceFill(['status_before_hold' => $status->value]);
                    $this->transition($s, SubscriptionStatus::Paused, 'paused', $staffId, $reason, null, null, null, 'staff');
                    break;
                case 'suspend':
                    $s->forceFill(['status_before_hold' => $status === SubscriptionStatus::Suspended ? $s->status_before_hold : $status->value]);
                    $this->transition($s, SubscriptionStatus::Suspended, 'suspended', $staffId, $reason, null, null, null, 'staff');
                    break;
                case 'unsuspend':
                case 'resume':
                    $back = SubscriptionStatus::tryFrom((string) $s->status_before_hold) ?? SubscriptionStatus::Active;
                    if ($back === SubscriptionStatus::Paused || $back === SubscriptionStatus::Suspended) {
                        $back = SubscriptionStatus::Active;
                    }
                    $s->forceFill(['status_before_hold' => null]);
                    $this->transition($s, $back, $action === 'resume' ? 'resumed' : 'unsuspended', $staffId, $reason, null, null, null, 'staff');
                    break;
                case 'extend':
                    $days = max(1, min(366, (int) $days));
                    $base = $s->current_period_end !== null && $s->current_period_end->isFuture() ? CarbonImmutable::instance($s->current_period_end) : CarbonImmutable::now();
                    $s->forceFill(['current_period_end' => $base->addDays($days), 'grace_ends_at' => null]);
                    if ($status === SubscriptionStatus::Trialing) {
                        $s->forceFill(['trial_ends_at' => $base->addDays($days)]);
                        $this->transition($s, SubscriptionStatus::Trialing, 'extended', $staffId, $reason, ['days' => $days], null, null, 'staff');
                    } else {
                        $this->transition($s, $status === SubscriptionStatus::Cancelled ? SubscriptionStatus::Cancelled : SubscriptionStatus::Active, 'extended', $staffId, $reason, ['days' => $days], null, null, 'staff');
                    }
                    break;
                default:
                    throw new ApiException(422, 'unknown_action', __('billing.errors.unknown_action'));
            }
            Audit::record('billing.subscription_'.$action, $s, ['reason' => $reason, 'days' => $days], $s->workspace_id, $staffId, 'staff');

            return $s;
        });
    }

    public function setReminders(Subscription $sub, bool $on): Subscription
    {
        return $this->locked($sub, function (Subscription $s) use ($on): Subscription {
            $s->forceFill(['renewal_reminders' => $on])->save();

            return $s;
        });
    }

    // ------------------------------------------------------------------ scheduler

    /**
     * Lifecycle tick (sitex:billing:lifecycle). Idempotent: each step looks at
     * the current state under a row lock. Never auto-debits (bank transfer /
     * QR customers pay themselves): an unpaid period end moves to PAST_DUE /
     * GRACE_PERIOD and then EXPIRED, data untouched.
     *
     * @return array<string, int>
     */
    public function tick(): array
    {
        $out = ['trials_expired' => 0, 'renewed_free' => 0, 'past_due' => 0, 'grace' => 0, 'expired' => 0, 'downgraded' => 0, 'reminders' => 0];
        $now = CarbonImmutable::now();

        // Platform-level scan for due rows (ids only); each row is then handled in its tenant context.
        $due = DB::table('subscriptions')->whereNotNull('live_key')->where(function ($q) use ($now): void {
            $q->where('current_period_end', '<=', $now)->orWhere('grace_ends_at', '<=', $now)->orWhere('trial_ends_at', '<=', $now);
        })->limit(1000)->get(['id', 'workspace_id']);

        foreach ($due as $row) {
            InTenant::run((string) $row->workspace_id, function () use ($row, $now, &$out): void {
                DB::transaction(function () use ($row, $now, &$out): void {
                    $s = Subscription::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($s === null || $s->live_key === null) {
                        return;
                    }
                    $status = SubscriptionStatus::from($s->status);
                    if ($status === SubscriptionStatus::Trialing && $s->trial_ends_at !== null && $s->trial_ends_at->lessThanOrEqualTo($now)) {
                        $s->forceFill(['ended_at' => now()]);
                        $this->transition($s, SubscriptionStatus::Expired, 'trial_expired', null, 'trial ended without payment');
                        $this->notifier->send($s->workspace_id, 'subscription_expired', 'expired:'.$s->id.':trial', []);
                        $out['trials_expired']++;

                        return;
                    }
                    if (in_array($status, [SubscriptionStatus::PastDue, SubscriptionStatus::GracePeriod], true) && $s->grace_ends_at !== null && $s->grace_ends_at->lessThanOrEqualTo($now)) {
                        $this->expire($s, 'grace_ended');
                        $out['expired']++;

                        return;
                    }
                    if ($s->current_period_end === null || $s->current_period_end->greaterThan($now)
                        || ! in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Cancelled], true)) {
                        return;
                    }
                    if ($status === SubscriptionStatus::Cancelled) {
                        $this->expire($s, 'cancelled_period_ended');
                        $out['expired']++;

                        return;
                    }
                    $scheduled = SubscriptionChange::query()->where('subscription_id', $s->id)->where('status', 'scheduled')->lockForUpdate()->first();
                    $target = $scheduled !== null ? $this->catalog->version($scheduled->to_version_id) : $this->catalog->version($s->plan_version_id);
                    $targetPlan = $this->catalog->plan($target->plan_id);
                    $targetInterval = $scheduled !== null ? $scheduled->to_interval : $s->interval;
                    if (Catalog::isFree($targetPlan, $target) || (int) Catalog::price($target, $targetInterval) === 0) {
                        // Free plans (or a downgrade to free) roll over automatically: nothing to pay.
                        $start = CarbonImmutable::instance($s->current_period_end);
                        $s->forceFill(['plan_id' => $target->plan_id, 'plan_version_id' => $target->id, 'price_minor' => 0, 'interval' => $targetInterval,
                            'current_period_start' => $start, 'current_period_end' => self::addInterval($start, $targetInterval)]);
                        if ($scheduled !== null) {
                            $scheduled->forceFill(['status' => 'applied', 'applied_at' => now()])->save();
                            $out['downgraded']++;
                        }
                        $this->transition($s, SubscriptionStatus::Active, $scheduled !== null ? 'downgraded' : 'renewed', null, null, ['free' => true]);
                        $this->grantIncluded($s, $target);
                        $out['renewed_free']++;

                        return;
                    }
                    $graceDays = max(0, $this->settings->int('grace_days'));
                    $pending = PaymentOrder::query()->where('subscription_id', $s->id)->where('purpose', 'renewal')->whereIn('status', ['PENDING', 'PROCESSING'])->exists();
                    if ($graceDays === 0) {
                        $this->expire($s, 'period_ended_unpaid');
                        $out['expired']++;

                        return;
                    }
                    $s->forceFill(['grace_ends_at' => CarbonImmutable::instance($s->current_period_end)->addDays($graceDays)]);
                    $this->transition($s, $pending ? SubscriptionStatus::PastDue : SubscriptionStatus::GracePeriod, 'period_ended_unpaid', null, null, ['grace_days' => $graceDays]);
                    $this->notifier->send($s->workspace_id, 'renewal_due', 'renewal_due:'.$s->id.':'.$s->current_period_end->getTimestamp(), ['grace_ends_at' => $s->grace_ends_at?->toIso8601ZuluString()]);
                    $out[$pending ? 'past_due' : 'grace']++;
                });
            });
        }

        // Upcoming expiry reminders (deduplicated per subscription/period/day-mark).
        foreach ((array) $this->settings->get('renewal_notice_days') as $days) {
            $days = (int) $days;
            if ($days <= 0) {
                continue;
            }
            DB::table('subscriptions')->whereIn('status', ['ACTIVE', 'TRIALING'])->where('renewal_reminders', true)->where('price_minor', '>', 0)
                ->whereBetween('current_period_end', [$now, $now->addDays($days)])->limit(1000)->get(['id', 'workspace_id', 'current_period_end'])
                ->each(function (object $r) use ($days, &$out): void {
                    $end = CarbonImmutable::parse((string) $r->current_period_end);
                    if ($this->notifier->send((string) $r->workspace_id, 'expiring_soon', 'expiring:'.$r->id.':'.$end->getTimestamp().':'.$days, ['ends_at' => $end->toIso8601ZuluString(), 'days' => $days])) {
                        $out['reminders']++;
                    }
                });
        }

        return $out;
    }

    private function expire(Subscription $s, string $event): void
    {
        $s->forceFill(['ended_at' => now(), 'grace_ends_at' => null]);
        $this->transition($s, SubscriptionStatus::Expired, $event, null, null);
        SubscriptionChange::query()->where('subscription_id', $s->id)->whereIn('status', ['scheduled', 'pending_payment'])->get()
            ->each(fn (SubscriptionChange $c) => $c->forceFill(['status' => 'cancelled'])->save());
        $this->notifier->send($s->workspace_id, 'subscription_expired', 'expired:'.$s->id.':'.($s->current_period_end?->getTimestamp() ?? 0), []);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function transition(
        Subscription $sub, SubscriptionStatus $to, string $event, ?string $actorId, ?string $reason, ?array $data = null,
        ?SubscriptionStatus $from = null, ?string $orderId = null, string $actorType = 'system',
    ): void {
        $from ??= SubscriptionStatus::from($sub->getOriginal('status') ?? $sub->status);
        if ($from !== $to && ! $from->canTransitionTo($to)) {
            throw new InvalidTransition($from, $to);
        }
        $sub->forceFill([
            'status' => $to->value,
            'live_key' => $to->isLive() ? $sub->workspace_id : null,
            'lock_version' => $sub->lock_version + 1,
        ])->save();
        $this->event($sub, $event, $from, $to, $actorId, $reason, $data, $orderId, $actorId !== null && $actorType === 'system' ? 'user' : $actorType);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function event(Subscription $sub, string $event, ?SubscriptionStatus $from, ?SubscriptionStatus $to, ?string $actorId, ?string $reason, ?array $data, ?string $orderId = null, string $actorType = 'system'): void
    {
        $e = new SubscriptionEvent;
        $e->forceFill([
            'subscription_id' => $sub->id, 'event' => $event, 'from_status' => $from?->value, 'to_status' => $to?->value,
            'plan_version_id' => $sub->plan_version_id, 'payment_order_id' => $orderId,
            'actor_type' => $actorId !== null && $actorType === 'system' ? 'user' : $actorType, 'actor_id' => $actorId,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null, 'data' => $data, 'created_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function create(Plan $plan, PlanVersion $version, SubscriptionStatus $status, string $interval, int $price, CarbonInterface $start, ?CarbonInterface $end, array $extra = []): Subscription
    {
        $sub = new Subscription;
        $sub->forceFill(array_merge([
            'plan_id' => $plan->id, 'plan_version_id' => $version->id, 'status' => $status->value, 'interval' => $interval,
            'price_minor' => $price, 'currency' => $version->currency, 'started_at' => $start, 'current_period_start' => $start,
            'current_period_end' => $end, 'renewal_reminders' => true, 'lock_version' => 0,
        ], $extra));
        $sub->setAttribute('live_key', app(TenantContext::class)->workspaceId());
        $sub->save();

        return $sub;
    }

    private function grantIncluded(Subscription $sub, PlanVersion $version): void
    {
        if ($version->included_credits_mc <= 0) {
            return;
        }
        $this->credits->grant(
            $sub->workspace_id, 'included', $version->included_credits_mc, $this->includedExpiry($sub),
            'sub:'.$sub->id.':'.$sub->current_period_start->getTimestamp(), description: 'included credits',
            referenceType: 'subscription', referenceId: $sub->id,
        );
    }

    private function includedExpiry(Subscription $sub): ?CarbonInterface
    {
        return $this->settings->bool('included_credits_expire') ? $sub->current_period_end : null;
    }

    private function endFreeSubscription(string $workspaceId, string $event, ?string $actorId): void
    {
        $live = Subscription::query()->where('live_key', $workspaceId)->lockForUpdate()->first();
        if ($live !== null && $this->isFreeSub($live)) {
            $live->forceFill(['ended_at' => now()]);
            $this->transition($live, SubscriptionStatus::Expired, $event, $actorId, null);
        }
    }

    private function isFreeSub(Subscription $sub): bool
    {
        if ($sub->price_minor > 0 || $sub->status === SubscriptionStatus::Trialing->value) {
            return false;
        }
        $plan = Plan::query()->find($sub->plan_id);

        return $plan !== null && $plan->is_free;
    }

    /**
     * @template T
     *
     * @param  callable(Subscription): T  $callback
     * @return T
     */
    private function locked(Subscription $sub, callable $callback): mixed
    {
        return InTenant::run($sub->workspace_id, fn () => DB::transaction(function () use ($sub, $callback) {
            $s = Subscription::query()->whereKey($sub->id)->lockForUpdate()->firstOrFail();

            return $callback($s);
        }));
    }

    public static function addInterval(CarbonInterface $from, string $interval): CarbonImmutable
    {
        $c = CarbonImmutable::instance($from);

        return $interval === 'year' ? $c->addYearNoOverflow() : $c->addMonthNoOverflow();
    }

    public static function emailHash(string $email): string
    {
        // Normalise gmail-style aliases so "a+1@x" cannot farm trials.
        $email = mb_strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $local = explode('+', $local, 2)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
        }

        return hash('sha256', $local.'@'.$domain);
    }
}
