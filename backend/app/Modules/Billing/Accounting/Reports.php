<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Billing\Support\BillingSettings;

/**
 * Revenue, cost and profitability reports (REVENUE section of
 * BILLING-ARCHITECTURE.md). Platform-level, read-only aggregates over billing
 * tables (query builder, explicit workspace filter when one is given).
 *
 * Accounting policy implemented (configurable later, documented):
 * - cash collected = payments received in the range (refunds negative);
 * - subscription revenue is recognised linearly over the service period;
 *   the rest is deferred revenue;
 * - purchased credits are a liability until consumed (or expired → breakage);
 * - AI cost = provider cost (cost_records, micro-USD), converted with
 *   `usd_to_currency_rate` (minor units per USD) only if staff set it —
 *   otherwise profit is null ("Chưa có dữ liệu"), never guessed.
 */
final class Reports
{
    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function revenue(CarbonImmutable $from, CarbonImmutable $to, ?string $workspaceId = null): array
    {
        $currency = (string) $this->settings->get('currency');
        $records = DB::table('revenue_records')->where('currency', $currency)->where('occurred_at', '<=', $to)
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get(['workspace_id', 'kind', 'amount_minor', 'discount_minor', 'service_start', 'service_end', 'occurred_at', 'credit_grant_id', 'plan_id']);

        $gross = 0;
        $discounts = 0;
        $refunds = 0;
        $subscriptionCash = 0;
        $creditCash = 0;
        $recognizedSub = 0;
        $deferred = 0;
        foreach ($records as $r) {
            $at = CarbonImmutable::parse((string) $r->occurred_at);
            $amount = (int) $r->amount_minor;
            $inRange = $at->greaterThanOrEqualTo($from) && $at->lessThanOrEqualTo($to);
            if ($inRange) {
                if ($r->kind === 'refund') {
                    $refunds += $amount;
                } else {
                    $gross += $amount;
                    $discounts += (int) $r->discount_minor;
                    $r->kind === 'credits' ? $creditCash += $amount : $subscriptionCash += $amount;
                }
            }
            if ($r->kind === 'subscription') {
                [$rec, $def] = self::recognize($amount, $r->service_start, $r->service_end, $at, $from, $to);
                $recognizedSub += $rec;
                $deferred += $def;
            }
        }

        $credit = $this->creditRevenue($from, $to, $workspaceId, $currency);
        $costs = $this->costs($from, $to, $workspaceId);
        $fees = (int) DB::table('payment_orders')->where('currency', $currency)->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))->sum('fee_minor');

        $recognized = $recognizedSub + $credit['recognized_minor'];
        $aiCostMinor = $this->toCurrency($costs['total_micro_usd']);
        $profit = $aiCostMinor !== null ? $recognized + $refunds - $aiCostMinor - $fees : null;

        return [
            'currency' => $currency,
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'cash' => [
                'gross_minor' => $gross, 'discounts_minor' => $discounts, 'refunds_minor' => $refunds, 'net_minor' => $gross + $refunds,
                'subscription_minor' => $subscriptionCash, 'credits_minor' => $creditCash,
            ],
            'recognized' => [
                'subscription_minor' => $recognizedSub, 'credits_minor' => $credit['recognized_minor'], 'total_minor' => $recognized,
            ],
            'deferred_subscription_minor' => $deferred,
            'credit_liability_minor' => $credit['liability_minor'],
            'credits_consumed_mc' => $credit['consumed_mc'],
            'costs' => $costs + ['payment_fees_minor' => $fees, 'ai_cost_minor' => $aiCostMinor],
            'gross_profit_minor' => $profit,
            'gross_margin_bps' => $profit !== null && $recognized > 0 ? intdiv($profit * 10000, $recognized) : null,
            'exchange_rate' => $this->settings->get('usd_to_currency_rate'),
        ];
    }

    /**
     * @return array{total_micro_usd: int, uncertain_micro_usd: int, by_category: array<string, int>, by_model: list<array{provider: string, model: string, cost_micro_usd: int, requests: int}>, trend: list<array{day: string, cost_micro_usd: int}>}
     */
    public function costs(CarbonImmutable $from, CarbonImmutable $to, ?string $workspaceId = null): array
    {
        $base = fn () => DB::table('cost_records')->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId));

        $byCat = $base()->selectRaw('category, SUM(cost_micro_usd) AS c')->groupBy('category')->pluck('c', 'category')->map(fn ($v) => (int) $v)->all();
        $byModel = $base()->selectRaw('provider, model, SUM(cost_micro_usd) AS c, SUM(requests) AS n')->groupBy('provider', 'model')->orderByDesc('c')->limit(20)->get()
            ->map(fn ($r) => ['provider' => (string) $r->provider, 'model' => (string) $r->model, 'cost_micro_usd' => (int) $r->c, 'requests' => (int) $r->n])->all();
        $trend = $base()->selectRaw('day, SUM(cost_micro_usd) AS c')->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => substr((string) $r->day, 0, 10), 'cost_micro_usd' => (int) $r->c])->all();

        return [
            'total_micro_usd' => array_sum($byCat),
            'uncertain_micro_usd' => (int) $base()->sum('uncertain_micro_usd'),
            'by_category' => $byCat,
            'by_model' => array_values($byModel),
            'trend' => array_values($trend),
        ];
    }

    /**
     * Customer profitability (Super Admin). Alerts are informational: nobody
     * is blocked for low margin without an explicit policy.
     *
     * @return array<string, mixed>
     */
    public function profitability(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $currency = (string) $this->settings->get('currency');
        $revenue = [];
        $records = DB::table('revenue_records')->where('currency', $currency)->where('occurred_at', '<=', $to)
            ->get(['workspace_id', 'kind', 'amount_minor', 'service_start', 'service_end', 'occurred_at']);
        foreach ($records as $r) {
            $ws = (string) $r->workspace_id;
            $revenue[$ws] ??= ['cash' => 0, 'recognized' => 0];
            $at = CarbonImmutable::parse((string) $r->occurred_at);
            if ($at->greaterThanOrEqualTo($from)) {
                $revenue[$ws]['cash'] += (int) $r->amount_minor;
            }
            if ($r->kind === 'subscription') {
                $revenue[$ws]['recognized'] += self::recognize((int) $r->amount_minor, $r->service_start, $r->service_end, $at, $from, $to)[0];
            } elseif ($r->kind === 'refund' && $at->greaterThanOrEqualTo($from)) {
                $revenue[$ws]['recognized'] += (int) $r->amount_minor;
            }
        }
        foreach ($this->creditRevenueByWorkspace($from, $to, $currency) as $ws => $v) {
            $revenue[$ws] ??= ['cash' => 0, 'recognized' => 0];
            $revenue[$ws]['recognized'] += $v;
        }
        $cost = DB::table('cost_records')->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('workspace_id, SUM(cost_micro_usd) AS c')->groupBy('workspace_id')->pluck('c', 'workspace_id')->map(fn ($v) => (int) $v)->all();
        $credits = DB::table('credit_ledger_entries')->where('entry_type', 'consume')->whereBetween('created_at', [$from, $to])
            ->selectRaw('workspace_id, SUM(amount_mc) AS s')->groupBy('workspace_id')->pluck('s', 'workspace_id')->map(fn ($v) => -(int) $v)->all();

        $ids = array_values(array_unique(array_merge(array_keys($revenue), array_keys($cost), array_keys($credits))));
        $names = DB::table('workspaces')->whereIn('id', $ids)->pluck('name', 'id')->all();
        $plans = DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->whereNotNull('subscriptions.live_key')
            ->whereIn('subscriptions.workspace_id', $ids)->pluck('plans.code', 'subscriptions.workspace_id')->all();
        $totalCost = max(1, array_sum($cost));
        $lossBps = (int) $this->settings->get('loss_alert_margin_bps');
        $heavyBps = max(1, (int) $this->settings->get('heavy_user_cost_share_bps'));

        $rows = [];
        $byPlan = [];
        foreach ($ids as $ws) {
            $rec = $revenue[$ws]['recognized'] ?? 0;
            $c = $cost[$ws] ?? 0;
            $cMinor = $this->toCurrency($c);
            $profit = $cMinor !== null ? $rec - $cMinor : null;
            $margin = $profit !== null && $rec > 0 ? intdiv($profit * 10000, $rec) : null;
            $flags = [];
            if ($profit !== null && ($profit < 0 || ($margin !== null && $margin < $lossBps))) {
                $flags[] = 'loss_making';
            }
            if ($c > 0 && $rec <= 0) {
                $flags[] = 'cost_without_revenue';
            }
            if (intdiv($c * 10000, $totalCost) >= $heavyBps) {
                $flags[] = 'heavy_user';
            }
            $plan = $plans[$ws] ?? null;
            $rows[] = [
                'workspace_id' => $ws, 'workspace_name' => $names[$ws] ?? null, 'plan_code' => $plan,
                'cash_minor' => $revenue[$ws]['cash'] ?? 0, 'recognized_minor' => $rec, 'ai_cost_micro_usd' => $c, 'ai_cost_minor' => $cMinor,
                'credits_consumed_mc' => $credits[$ws] ?? 0, 'gross_profit_minor' => $profit, 'margin_bps' => $margin, 'flags' => $flags,
            ];
            $key = $plan ?? '—';
            $byPlan[$key] ??= ['plan_code' => $plan, 'tenants' => 0, 'recognized_minor' => 0, 'ai_cost_micro_usd' => 0];
            $byPlan[$key]['tenants']++;
            $byPlan[$key]['recognized_minor'] += $rec;
            $byPlan[$key]['ai_cost_micro_usd'] += $c;
        }
        usort($rows, fn ($a, $b) => $b['ai_cost_micro_usd'] <=> $a['ai_cost_micro_usd']);
        foreach ($byPlan as &$p) {
            $pc = $this->toCurrency($p['ai_cost_micro_usd']);
            $p['gross_profit_minor'] = $pc !== null ? $p['recognized_minor'] - $pc : null;
            $p['margin_bps'] = $p['gross_profit_minor'] !== null && $p['recognized_minor'] > 0 ? intdiv($p['gross_profit_minor'] * 10000, $p['recognized_minor']) : null;
        }
        unset($p);

        return [
            'currency' => $currency,
            'exchange_rate' => $this->settings->get('usd_to_currency_rate'),
            'tenants' => $rows,
            'alerts' => array_values(array_filter($rows, fn ($r) => $r['flags'] !== [])),
            'by_plan' => array_values($byPlan),
            'costs' => $this->costs($from, $to),
        ];
    }

    /**
     * Linear recognition of `amount` over [start, end] within [from, to];
     * returns [recognised in range, deferred after `to`].
     *
     * @return array{0: int, 1: int}
     */
    public static function recognize(int $amount, mixed $start, mixed $end, CarbonImmutable $occurredAt, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($occurredAt->greaterThan($to)) {
            return [0, 0];
        }
        if ($start === null || $end === null) {
            return [$occurredAt->greaterThanOrEqualTo($from) ? $amount : 0, 0];
        }
        $s = CarbonImmutable::parse((string) $start)->getTimestamp();
        $e = CarbonImmutable::parse((string) $end)->getTimestamp();
        $len = max(1, $e - $s);
        $overlap = max(0, min($e, $to->getTimestamp()) - max($s, $from->getTimestamp()));
        $after = max(0, $e - max($s, $to->getTimestamp()));

        return [intdiv($amount * $overlap, $len), intdiv($amount * $after, $len)];
    }

    /**
     * @return array{recognized_minor: int, liability_minor: int, consumed_mc: int}
     */
    private function creditRevenue(CarbonImmutable $from, CarbonImmutable $to, ?string $workspaceId, string $currency): array
    {
        $grants = DB::table('credit_grants')->where('source', 'purchased')->where('currency', $currency)->where('price_minor', '>', 0)
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get(['id', 'amount_mc', 'remaining_mc', 'price_minor'])->keyBy('id');
        $liability = 0;
        foreach ($grants as $g) {
            $liability += intdiv((int) $g->price_minor * (int) $g->remaining_mc, max(1, (int) $g->amount_mc));
        }
        $recognized = 0;
        if ($grants->isNotEmpty()) {
            $used = DB::table('credit_ledger_entries')->whereIn('grant_id', $grants->keys()->all())->whereIn('entry_type', ['consume', 'expire', 'adjust'])
                ->whereBetween('created_at', [$from, $to])->selectRaw('grant_id, SUM(amount_mc) AS s')->groupBy('grant_id')->pluck('s', 'grant_id');
            foreach ($used as $gid => $s) {
                $g = $grants[$gid];
                $recognized += intdiv((int) $g->price_minor * -(int) $s, max(1, (int) $g->amount_mc));
            }
        }
        $consumed = -(int) DB::table('credit_ledger_entries')->where('entry_type', 'consume')->whereBetween('created_at', [$from, $to])
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))->sum('amount_mc');

        return ['recognized_minor' => $recognized, 'liability_minor' => $liability, 'consumed_mc' => $consumed];
    }

    /**
     * @return array<string, int>
     */
    private function creditRevenueByWorkspace(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $rows = DB::table('credit_ledger_entries')->join('credit_grants', 'credit_grants.id', '=', 'credit_ledger_entries.grant_id')
            ->where('credit_grants.source', 'purchased')->where('credit_grants.currency', $currency)->where('credit_grants.price_minor', '>', 0)
            ->whereIn('credit_ledger_entries.entry_type', ['consume', 'expire', 'adjust'])->whereBetween('credit_ledger_entries.created_at', [$from, $to])
            ->get(['credit_ledger_entries.workspace_id', 'credit_ledger_entries.amount_mc', 'credit_grants.price_minor', 'credit_grants.amount_mc as grant_mc']);
        $out = [];
        foreach ($rows as $r) {
            $ws = (string) $r->workspace_id;
            $out[$ws] = ($out[$ws] ?? 0) + intdiv((int) $r->price_minor * -(int) $r->amount_mc, max(1, (int) $r->grant_mc));
        }

        return $out;
    }

    public function toCurrency(int $microUsd): ?int
    {
        $rate = $this->settings->get('usd_to_currency_rate');
        if (! is_numeric($rate) || (int) $rate <= 0) {
            return null;
        }

        return intdiv($microUsd * (int) $rate + 999_999, 1_000_000);
    }
}
