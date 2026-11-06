<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SiteXPost\Modules\AiGateway\Domain\AiBudget;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetCounter;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetReservation;

/**
 * Atomic budget (quota) reservations — reserve → commit | release.
 *
 * - Every check-and-increment happens in ONE transaction holding row locks
 *   on the counters (SELECT … FOR UPDATE, locked in a fixed order), so two
 *   workers can never both pass the same remaining budget.
 * - Reservations are keyed by a unique idempotency key: reserving twice
 *   returns the first reservation, committing/releasing twice is a no-op —
 *   a retried job can never charge the same call twice.
 * - Expired reservations (worker died mid-call, outcome unknown) are
 *   committed at their estimate: we prefer over-counting to overspending.
 */
final class BudgetManager
{
    /**
     * @return Collection<int, AiBudget>
     */
    public function budgetsFor(?string $workspaceId): Collection
    {
        $keys = ['global'];
        if ($workspaceId !== null) {
            $keys[] = AiBudget::scopeKeyFor($workspaceId);
        }

        return AiBudget::query()->where('is_enabled', true)->whereIn('scope_key', $keys)
            ->orderBy('scope_key')->orderBy('period')->get();
    }

    /**
     * @throws BudgetExceeded
     */
    public function reserve(string $key, ?string $workspaceId, int $amount, string $priority = 'batch'): AiBudgetReservation
    {
        $budgets = $this->budgetsFor($workspaceId);
        // Counter rows are created up front, outside the locking transaction:
        // concurrent first inserts inside it deadlock on InnoDB gap locks.
        foreach ($budgets as $budget) {
            $this->ensureCounter($budget);
        }

        return $this->transaction(function () use ($key, $workspaceId, $amount, $priority, $budgets): AiBudgetReservation {
            // Plain read (no gap lock); a concurrent insert of the same key is
            // caught by the unique index and retried in transaction().
            $existing = AiBudgetReservation::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            $counterIds = [];
            foreach ($budgets as $budget) {
                $counter = $this->lockedCounter($budget);
                $blocks = $budget->action_on_exceed === 'pause_all'
                    || ($budget->action_on_exceed === 'pause_batch' && $priority === 'batch');

                if ($blocks && $counter->spent_micro_usd + $counter->reserved_micro_usd + $amount > $budget->limit_micro_usd) {
                    throw new BudgetExceeded($budget->scope_key, $budget->period, $budget->limit_micro_usd);
                }

                $counter->reserved_micro_usd += $amount;
                $counter->save();
                $counterIds[] = $counter->id;
            }

            $reservation = new AiBudgetReservation;
            $reservation->forceFill([
                'idempotency_key' => $key,
                'workspace_id' => $workspaceId,
                'status' => 'reserved',
                'amount_micro_usd' => $amount,
                'counter_ids' => $counterIds,
                'expires_at' => now()->addMinutes(max(1, (int) config('sitex.ai.reservation_ttl_minutes', 30))),
            ])->save();

            return $reservation;
        });
    }

    /**
     * Charge the real cost. Returns false when the reservation was already
     * settled (idempotent: never charges twice).
     */
    public function commit(string $key, int $actual, string $status = 'committed'): bool
    {
        return $this->settle($key, $actual, $status);
    }

    public function release(string $key): bool
    {
        return $this->settle($key, null, 'released');
    }

    /**
     * Commit reservations whose call outcome is unknown (worker crashed).
     */
    public function expireStale(): int
    {
        $count = 0;
        AiBudgetReservation::query()->where('status', 'reserved')->where('expires_at', '<', now())
            ->orderBy('expires_at')->limit(500)->pluck('idempotency_key')
            ->each(function (string $key) use (&$count): void {
                if ($this->settle($key, null, 'expired')) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * True when any applicable budget is past its alert threshold — the
     * router then prefers the task's economy model.
     */
    public function isNearLimit(?string $workspaceId): bool
    {
        foreach ($this->budgetsFor($workspaceId) as $budget) {
            $s = $this->status($budget);
            if ($s['used_pct'] >= $budget->alert_threshold_pct) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{period_start: string, spent_micro_usd: int, reserved_micro_usd: int, used_pct: float, alerted_at: string|null}
     */
    public function status(AiBudget $budget): array
    {
        $start = AiBudget::periodStart($budget->period);
        $counter = AiBudgetCounter::query()->where('scope_key', $budget->scope_key)
            ->where('period', $budget->period)->where('period_start', $start)->first();

        $spent = $counter !== null ? $counter->spent_micro_usd : $this->spentFromLog($budget, $start);
        $reserved = $counter !== null ? $counter->reserved_micro_usd : 0;
        $pct = $budget->limit_micro_usd > 0 ? round(($spent + $reserved) * 100 / $budget->limit_micro_usd, 1) : 100.0;

        return [
            'period_start' => $start,
            'spent_micro_usd' => $spent,
            'reserved_micro_usd' => $reserved,
            'used_pct' => $pct,
            'alerted_at' => $counter?->alerted_at?->toIso8601ZuluString(),
        ];
    }

    private function settle(string $key, ?int $actual, string $status): bool
    {
        $alerts = [];
        $settled = $this->transaction(function () use ($key, $actual, $status, &$alerts): bool {
            $reservation = AiBudgetReservation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($reservation === null || $reservation->status !== 'reserved') {
                return false;
            }

            $charge = $status === 'released' ? 0 : ($actual ?? $reservation->amount_micro_usd);
            $ids = $reservation->counter_ids;
            sort($ids);

            foreach ($ids as $id) {
                $counter = AiBudgetCounter::query()->whereKey($id)->lockForUpdate()->first();
                if ($counter === null) {
                    continue;
                }
                $counter->reserved_micro_usd = max(0, $counter->reserved_micro_usd - $reservation->amount_micro_usd);
                $counter->spent_micro_usd += $charge;

                $budget = AiBudget::query()->where('scope_key', $counter->scope_key)->where('period', $counter->period)->first();
                if ($budget !== null && $counter->alerted_at === null && $budget->limit_micro_usd > 0
                    && $counter->spent_micro_usd * 100 >= $budget->limit_micro_usd * $budget->alert_threshold_pct) {
                    $counter->alerted_at = now();
                    $alerts[] = ['scope' => $budget->scope_key, 'period' => $budget->period, 'spent_micro_usd' => $counter->spent_micro_usd, 'limit_micro_usd' => $budget->limit_micro_usd];
                }
                $counter->save();
            }

            $reservation->forceFill(['status' => $status, 'actual_micro_usd' => $charge, 'settled_at' => now()])->save();

            return true;
        });

        foreach ($alerts as $alert) {
            Log::warning('AI budget alert threshold reached', $alert);
        }

        return $settled;
    }

    private function ensureCounter(AiBudget $budget): void
    {
        $start = AiBudget::periodStart($budget->period);
        $exists = AiBudgetCounter::query()->where('scope_key', $budget->scope_key)
            ->where('period', $budget->period)->where('period_start', $start)->exists();

        if (! $exists) {
            // A budget created mid-period starts from what was already spent.
            AiBudgetCounter::query()->insertOrIgnore([
                'scope_key' => $budget->scope_key,
                'period' => $budget->period,
                'period_start' => $start,
                'reserved_micro_usd' => 0,
                'spent_micro_usd' => $this->spentFromLog($budget, $start),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function lockedCounter(AiBudget $budget): AiBudgetCounter
    {
        $start = AiBudget::periodStart($budget->period);

        // Exact match on the unique index → a single row lock.
        return AiBudgetCounter::query()->where('scope_key', $budget->scope_key)
            ->where('period', $budget->period)->where('period_start', $start)->lockForUpdate()->first()
            ?? throw new \RuntimeException('Budget counter could not be created.');
    }

    private function spentFromLog(AiBudget $budget, string $start): int
    {
        $end = $budget->period === 'day'
            ? now()->parse($start)->addDay()
            : now()->parse($start)->addMonth();

        // Query builder on purpose: aggregates across tenants for the global
        // scope; the workspace filter is explicit for workspace budgets.
        $query = DB::table('ai_requests')->where('created_at', '>=', $start.' 00:00:00')->where('created_at', '<', $end->toDateTimeString());
        if ($budget->workspace_id !== null) {
            $query->where('workspace_id', $budget->workspace_id);
        }

        return (int) $query->sum('cost_micro_usd');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        // DB::transaction() already re-runs the closure on deadlocks /
        // serialization failures (attempts).
        try {
            return DB::transaction($callback, 5);
        } catch (QueryException $e) {
            // Two first-time reservations for the same key racing on the unique index.
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '1062')) {
                return DB::transaction($callback, 5);
            }
            throw $e;
        }
    }
}
