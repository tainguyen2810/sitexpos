<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteXPost\Modules\Billing\Domain\BillingDocument;
use SiteXPost\Modules\Billing\Domain\BillingProfile;
use SiteXPost\Modules\Billing\Domain\CreditGrant;
use SiteXPost\Modules\Billing\Domain\CreditLedgerEntry;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Billing\Domain\CreditPolicy;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\PaymentProviderConfig;
use SiteXPost\Modules\Billing\Domain\Plan;
use SiteXPost\Modules\Billing\Domain\PlanVersion;
use SiteXPost\Modules\Billing\Domain\Refund;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\SubscriptionChange;
use SiteXPost\Modules\Billing\Domain\SubscriptionEvent;
use SiteXPost\Modules\Billing\Payments\Adapters\AdapterRegistry;
use SiteXPost\Modules\Billing\Support\Catalog;

final class BillingPresenter
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function page(Builder $query, callable $map, Request $request, int $default = 25): JsonResponse
    {
        return self::cursor($query->cursorPaginate(self::perPage($request, $default)), $map, $request, $default);
    }

    public static function rows(QueryBuilder $query, callable $map, Request $request, int $default = 25): JsonResponse
    {
        return self::cursor($query->cursorPaginate(self::perPage($request, $default)), $map, $request, $default);
    }

    private static function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    /**
     * @param  CursorPaginator<int, mixed>  $page
     */
    private static function cursor(CursorPaginator $page, callable $map, Request $request, int $default): JsonResponse
    {
        $perPage = self::perPage($request, $default);

        return new JsonResponse([
            'data' => array_map($map, $page->items()),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'per_page' => $perPage],
        ]);
    }

    public static function ts(mixed $v): ?string
    {
        if ($v instanceof CarbonInterface) {
            return $v->toIso8601ZuluString();
        }

        return $v !== null ? CarbonImmutable::parse((string) $v)->toIso8601ZuluString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function plan(Plan $p, ?PlanVersion $current, bool $admin = false): array
    {
        $out = [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'is_free' => $p->is_free,
            'is_demo' => $p->is_demo, 'display_order' => $p->display_order,
            'current_version' => $current !== null ? self::version($current, app(Catalog::class)->entitlements($current->id)) : null,
        ];
        if ($admin) {
            $out += ['status' => $p->status, 'current_version_id' => $p->current_version_id, 'created_at' => self::ts($p->created_at), 'updated_at' => self::ts($p->updated_at)];
        }

        return $out;
    }

    /**
     * @param  array<string, array{limit: int, period: string}>  $entitlements
     * @return array<string, mixed>
     */
    public static function version(PlanVersion $v, array $entitlements): array
    {
        return [
            'id' => $v->id, 'plan_id' => $v->plan_id, 'version' => $v->version, 'status' => $v->status, 'currency' => $v->currency,
            'monthly_price_minor' => $v->monthly_price_minor, 'yearly_price_minor' => $v->yearly_price_minor,
            'trial_days' => $v->trial_days, 'trial_credits_mc' => $v->trial_credits_mc, 'included_credits_mc' => $v->included_credits_mc,
            'support_level' => $v->support_level, 'features' => Catalog::features($v), 'feature_flags' => $v->feature_flags ?? (object) [],
            'entitlements' => $entitlements === [] ? (object) [] : $entitlements, 'notes' => $v->notes,
            'published_at' => self::ts($v->published_at), 'created_at' => self::ts($v->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $s): array
    {
        $plan = Plan::query()->find($s->plan_id);
        $version = PlanVersion::query()->find($s->plan_version_id);

        return [
            'id' => $s->id, 'status' => $s->status, 'interval' => $s->interval, 'price_minor' => $s->price_minor, 'currency' => $s->currency,
            'plan' => $plan !== null ? ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'is_free' => $plan->is_free, 'is_demo' => $plan->is_demo] : null,
            'plan_version' => $version !== null ? ['id' => $version->id, 'version' => $version->version, 'included_credits_mc' => $version->included_credits_mc] : null,
            'started_at' => self::ts($s->started_at), 'current_period_start' => self::ts($s->current_period_start),
            'current_period_end' => self::ts($s->current_period_end), 'trial_ends_at' => self::ts($s->trial_ends_at),
            'grace_ends_at' => self::ts($s->grace_ends_at), 'cancelled_at' => self::ts($s->cancelled_at), 'ended_at' => self::ts($s->ended_at),
            'renewal_reminders' => $s->renewal_reminders, 'workspace_id' => $s->workspace_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function change(SubscriptionChange $c): array
    {
        $v = PlanVersion::query()->find($c->to_version_id);
        $plan = $v !== null ? Plan::query()->find($v->plan_id) : null;

        return [
            'id' => $c->id, 'kind' => $c->kind, 'status' => $c->status, 'to_plan' => $plan !== null ? ['code' => $plan->code, 'name' => $plan->name] : null,
            'to_interval' => $c->to_interval, 'effective_at' => self::ts($c->effective_at), 'amount_minor' => $c->amount_minor,
            'currency' => $c->currency, 'quote' => $c->quote, 'payment_order_id' => $c->payment_order_id, 'applied_at' => self::ts($c->applied_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(SubscriptionEvent $e): array
    {
        return [
            'id' => $e->id, 'event' => $e->event, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'actor_type' => $e->actor_type,
            'reason' => $e->reason, 'data' => $e->data, 'payment_order_id' => $e->payment_order_id, 'created_at' => self::ts($e->created_at),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $instructions
     * @return array<string, mixed>
     */
    public static function order(PaymentOrder $o, ?array $instructions = null): array
    {
        $out = [
            'id' => $o->id, 'purpose' => $o->purpose, 'description' => $o->description, 'amount_minor' => $o->amount_minor, 'currency' => $o->currency,
            'provider' => $o->provider, 'reference' => $o->reference, 'status' => $o->status, 'paid_amount_minor' => $o->paid_amount_minor,
            'refunded_amount_minor' => $o->refunded_amount_minor, 'interval' => $o->interval, 'snapshot' => $o->snapshot,
            'subscription_id' => $o->subscription_id, 'workspace_id' => $o->workspace_id,
            'expires_at' => self::ts($o->expires_at), 'paid_at' => self::ts($o->paid_at), 'cancelled_at' => self::ts($o->cancelled_at),
            'fulfilled_at' => self::ts($o->fulfilled_at), 'created_at' => self::ts($o->created_at),
        ];
        if ($instructions !== null) {
            $out['instructions'] = $instructions;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function grant(CreditGrant $g): array
    {
        return [
            'id' => $g->id, 'source' => $g->source, 'amount_mc' => $g->amount_mc, 'remaining_mc' => $g->remaining_mc,
            'expires_at' => self::ts($g->expires_at), 'expired_at' => self::ts($g->expired_at), 'created_at' => self::ts($g->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(CreditLedgerEntry $e): array
    {
        return [
            'id' => $e->id, 'entry_type' => $e->entry_type, 'amount_mc' => $e->amount_mc, 'balance_after_mc' => $e->balance_after_mc,
            'reference_type' => $e->reference_type, 'reference_id' => $e->reference_id, 'description' => $e->description,
            'grant_id' => $e->grant_id, 'created_at' => self::ts($e->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function package(CreditPackage $p, bool $admin = false): array
    {
        $out = [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'credits_mc' => $p->credits_mc, 'bonus_credits_mc' => $p->bonus_credits_mc,
            'price_minor' => $p->price_minor, 'currency' => $p->currency, 'validity_days' => $p->validity_days, 'is_demo' => $p->is_demo,
        ];

        return $admin ? $out + ['status' => $p->status, 'display_order' => $p->display_order] : $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function policy(CreditPolicy $p): array
    {
        return [
            'id' => $p->id, 'version' => $p->version, 'micro_usd_per_credit' => $p->micro_usd_per_credit, 'markup_bps' => $p->markup_bps,
            'min_charge_mc' => $p->min_charge_mc, 'effective_from' => self::ts($p->effective_from), 'notes' => $p->notes, 'created_at' => self::ts($p->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(BillingDocument $d): array
    {
        return [
            'id' => $d->id, 'type' => $d->type, 'number' => $d->number, 'amount_minor' => $d->amount_minor, 'currency' => $d->currency,
            'payment_order_id' => $d->payment_order_id, 'refund_id' => $d->refund_id, 'snapshot' => $d->snapshot,
            'is_tax_invoice' => false, 'issued_at' => self::ts($d->issued_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(?BillingProfile $p): array
    {
        return [
            'company_name' => $p?->company_name, 'tax_code' => $p?->tax_code, 'address' => $p?->address,
            'contact_name' => $p?->contact_name, 'email' => $p?->email, 'phone' => $p?->phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refund(Refund $r): array
    {
        return [
            'id' => $r->id, 'workspace_id' => $r->workspace_id, 'payment_order_id' => $r->payment_order_id, 'amount_minor' => $r->amount_minor,
            'currency' => $r->currency, 'credits_reversed_mc' => $r->credits_reversed_mc, 'status' => $r->status, 'reason' => $r->reason,
            'external_ref' => $r->external_ref, 'failure_reason' => $r->failure_reason, 'processed_at' => self::ts($r->processed_at),
            'created_at' => self::ts($r->created_at),
        ];
    }

    /**
     * Credentials are never returned — only which keys are set.
     *
     * @return array<string, mixed>
     */
    public static function provider(PaymentProviderConfig $c): array
    {
        $adapter = app(AdapterRegistry::class)->get($c->adapter);

        return [
            'id' => $c->id, 'code' => $c->code, 'adapter' => $c->adapter, 'name' => $c->name, 'is_enabled' => $c->is_enabled,
            'settings' => $c->settings ?? (object) [], 'credentials_set' => $c->credentialKeys(), 'capabilities' => $adapter->capabilities(),
            'required' => $adapter->requiredConfig(), 'display_order' => $c->display_order,
            'webhook_url' => $adapter->capabilities()['webhook'] ? url('/api/v1/billing/webhooks/'.$c->code) : null,
            'updated_at' => self::ts($c->updated_at),
        ];
    }

    /**
     * Customer-facing provider info (no settings beyond what payment needs).
     *
     * @return array<string, mixed>
     */
    public static function publicProvider(PaymentProviderConfig $c): array
    {
        return ['code' => $c->code, 'name' => $c->name, 'adapter' => $c->adapter];
    }
}
