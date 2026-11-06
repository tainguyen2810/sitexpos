<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Credits;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Billing\Domain\CreditGrant;
use SiteXPost\Modules\Billing\Domain\CreditLedgerEntry;
use SiteXPost\Modules\Billing\Domain\CreditPolicy;
use SiteXPost\Modules\Billing\Domain\CreditReservation;
use SiteXPost\Modules\Billing\Domain\CreditWallet;
use SiteXPost\Modules\Billing\Support\BillingSettings;
use SiteXPost\Modules\Billing\Support\InTenant;

/**
 * The credit wallet (CREDIT-LEDGER.md).
 *
 * - Source of truth = append-only `credit_ledger_entries`; `credit_wallets`
 *   balance/held are projections updated in the SAME transaction while the
 *   wallet row is locked (SELECT … FOR UPDATE) → concurrent writers serialise.
 * - Every write carries a unique idempotency key: a replayed grant, settle or
 *   adjustment is a no-op, never a second movement.
 * - Grants are buckets consumed earliest-expiry first (non-expiring last).
 * - Balance never goes below −overdraft_limit_mc (approved per-tenant policy,
 *   default 0). Cost above what a wallet can cover is recorded as
 *   `uncollected_mc` on the reservation (absorbed, visible in profitability),
 *   never as an unapproved negative balance.
 * - Invariant: balance_mc = Σ grants.remaining_mc − outstanding overdraft.
 */
final class CreditLedger
{
    public function __construct(private readonly BillingSettings $settings, private readonly CreditConverter $converter) {}

    public function wallet(string $workspaceId): CreditWallet
    {
        return InTenant::run($workspaceId, function (): CreditWallet {
            $wallet = CreditWallet::query()->first();
            if ($wallet !== null) {
                return $wallet;
            }
            try {
                $wallet = new CreditWallet;
                $wallet->forceFill(['balance_mc' => 0, 'held_mc' => 0, 'overdraft_limit_mc' => 0])->save();

                return $wallet;
            } catch (QueryException) {
                return CreditWallet::query()->firstOrFail(); // concurrent first creation
            }
        });
    }

    /**
     * @return array{balance_mc: int, held_mc: int, available_mc: int, overdraft_limit_mc: int}
     */
    public function summary(string $workspaceId): array
    {
        $w = $this->wallet($workspaceId);

        return [
            'balance_mc' => $w->balance_mc,
            'held_mc' => $w->held_mc,
            'available_mc' => max(0, $w->balance_mc - $w->held_mc + $w->overdraft_limit_mc),
            'overdraft_limit_mc' => $w->overdraft_limit_mc,
        ];
    }

    /**
     * Add credits. Idempotent per (workspace, source_ref).
     */
    public function grant(
        string $workspaceId, string $source, int $amountMc, ?CarbonInterface $expiresAt, string $sourceRef,
        int $priceMinor = 0, ?string $currency = null, ?string $description = null, ?string $actorId = null,
        ?string $referenceType = null, ?string $referenceId = null,
    ): CreditGrant {
        $wallet = $this->wallet($workspaceId);

        return InTenant::run($workspaceId, fn (): CreditGrant => $this->locked($wallet->id, function (CreditWallet $w) use ($source, $amountMc, $expiresAt, $sourceRef, $priceMinor, $currency, $description, $actorId, $referenceType, $referenceId): CreditGrant {
            $existing = CreditGrant::query()->where('source_ref', $sourceRef)->first();
            if ($existing !== null) {
                return $existing;
            }
            $amountMc = max(0, $amountMc);
            // A negative (approved overdraft) balance is paid off first.
            $payoff = min($amountMc, max(0, -$w->balance_mc));

            $grant = new CreditGrant;
            $grant->forceFill([
                'wallet_id' => $w->id, 'source' => $source, 'amount_mc' => $amountMc, 'remaining_mc' => $amountMc - $payoff,
                'expires_at' => $expiresAt, 'source_ref' => $sourceRef, 'price_minor' => $priceMinor, 'currency' => $currency,
            ])->save();

            $this->entry($w, 'grant', $amountMc, 'grant:'.$sourceRef, $grant->id, $referenceType, $referenceId, $description ?? $source, $actorId);

            return $grant;
        }));
    }

    /**
     * Hold credits for one metered call. Returns [reservation, created].
     *
     * @return array{0: CreditReservation, 1: bool}
     *
     * @throws InsufficientCredits
     */
    public function reserve(string $workspaceId, string $key, string $purpose, ?string $category, int $estimateMicroUsd, ?CreditPolicy $policy = null): array
    {
        $wallet = $this->wallet($workspaceId);
        $policy ??= $this->converter->current();

        return InTenant::run($workspaceId, fn (): array => $this->locked($wallet->id, function (CreditWallet $w) use ($key, $purpose, $category, $estimateMicroUsd, $policy): array {
            $existing = CreditReservation::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return [$existing, false];
            }
            $amount = $policy !== null ? CreditConverter::toCredits($estimateMicroUsd, $policy) : 0;
            $available = $w->balance_mc - $w->held_mc + $w->overdraft_limit_mc;
            if ($amount > $available) {
                throw new InsufficientCredits($amount, max(0, $available));
            }
            $w->forceFill(['held_mc' => $w->held_mc + $amount])->save();

            $r = new CreditReservation;
            $r->forceFill([
                'wallet_id' => $w->id, 'idempotency_key' => $key, 'purpose' => mb_substr($purpose, 0, 64), 'category' => $category,
                'policy_id' => $policy?->id, 'estimate_micro_usd' => $estimateMicroUsd, 'amount_mc' => $amount, 'status' => 'held',
                'expires_at' => now()->addMinutes(max(1, $this->settings->int('reservation_ttl_minutes'))),
            ])->save();

            return [$r, true];
        }));
    }

    /**
     * Charge the actual cost of a reserved call and release the rest.
     * `status`: settled (usage known) | uncertain (provider may have charged:
     * billed at max(actual, estimate) and flagged for review) | expired
     * (worker died: billed at the estimate). Returns false when the
     * reservation was already settled/released → retries never double charge.
     */
    public function settle(string $key, ?int $actualMicroUsd, string $status = 'settled'): bool
    {
        // Platform-level lookup (no tenant scope involved) to find the owning workspace.
        $res = DB::table('credit_reservations')->where('idempotency_key', $key)->first(['id', 'workspace_id']);
        if ($res === null) {
            return false;
        }

        return InTenant::run((string) $res->workspace_id, function () use ($key, $actualMicroUsd, $status): bool {
            $r = CreditReservation::query()->where('idempotency_key', $key)->first();
            if ($r === null) {
                return false;
            }

            return $this->locked($r->wallet_id, function (CreditWallet $w) use ($key, $actualMicroUsd, $status): bool {
                $r = CreditReservation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($r === null || $r->status !== 'held') {
                    return false;
                }
                $policy = $r->policy_id !== null ? CreditPolicy::query()->find($r->policy_id) : null;
                $cost = match ($status) {
                    'uncertain' => max((int) $actualMicroUsd, $r->estimate_micro_usd),
                    'expired' => $r->estimate_micro_usd,
                    default => max(0, (int) $actualMicroUsd),
                };
                $charge = $policy !== null ? CreditConverter::toCredits($cost, $policy) : 0;

                $held = max(0, $w->held_mc - $r->amount_mc);
                $w->held_mc = $held;
                $coverable = max(0, $w->balance_mc - $held + $w->overdraft_limit_mc);
                $charged = min($charge, $coverable);

                $this->consume($w, $charged, 'consume', 'consume:'.$r->id, 'reservation', $r->id, $r->purpose, null);
                $w->save();

                $r->forceFill([
                    'status' => $status, 'actual_micro_usd' => $cost, 'charged_mc' => $charged,
                    'uncollected_mc' => $charge - $charged, 'needs_review' => $status !== 'settled', 'settled_at' => now(),
                ])->save();

                return true;
            });
        });
    }

    public function release(string $key): bool
    {
        $res = DB::table('credit_reservations')->where('idempotency_key', $key)->first(['id', 'workspace_id', 'wallet_id']);
        if ($res === null) {
            return false;
        }

        return InTenant::run((string) $res->workspace_id, fn (): bool => $this->locked((string) $res->wallet_id, function (CreditWallet $w) use ($key): bool {
            $r = CreditReservation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($r === null || $r->status !== 'held') {
                return false;
            }
            $w->forceFill(['held_mc' => max(0, $w->held_mc - $r->amount_mc)])->save();
            $r->forceFill(['status' => 'released', 'charged_mc' => 0, 'settled_at' => now()])->save();

            return true;
        }));
    }

    /**
     * Staff adjustment: positive = new grant bucket, negative = debit
     * (never below zero available — no negative balance by adjustment).
     *
     * @throws InsufficientCredits
     */
    public function adjust(string $workspaceId, int $amountMc, string $kind, string $reason, ?CarbonInterface $expiresAt, string $idempotencyKey, ?string $actorId): ?int
    {
        if ($amountMc > 0) {
            $source = in_array($kind, ['promotional', 'bonus', 'refund'], true) ? $kind : 'adjustment';
            $grant = $this->grant($workspaceId, $source, $amountMc, $expiresAt, $idempotencyKey, description: $reason, actorId: $actorId, referenceType: 'adjustment', referenceId: $idempotencyKey);

            return CreditLedgerEntry::query()->where('idempotency_key', 'grant:'.$grant->source_ref)->value('id');
        }
        $wallet = $this->wallet($workspaceId);

        return InTenant::run($workspaceId, fn (): ?int => $this->locked($wallet->id, function (CreditWallet $w) use ($amountMc, $reason, $idempotencyKey, $actorId): ?int {
            $existing = CreditLedgerEntry::query()->where('idempotency_key', 'adjust:'.$idempotencyKey.':0')->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }
            $debit = -$amountMc;
            $available = $w->balance_mc - $w->held_mc;
            if ($debit > $available) {
                throw new InsufficientCredits($debit, max(0, $available));
            }
            $first = $this->consume($w, $debit, 'adjust', 'adjust:'.$idempotencyKey, 'adjustment', $idempotencyKey, $reason, $actorId);
            $w->save();

            return $first;
        }));
    }

    /**
     * Reverse (part of) a grant, e.g. refunded purchased credits. Only the
     * unused remainder can be reversed. Returns credits reversed.
     */
    public function reverseGrant(string $workspaceId, string $grantId, int $maxMc, string $idempotencyKey, string $reason, ?string $actorId): int
    {
        $wallet = $this->wallet($workspaceId);

        return InTenant::run($workspaceId, fn (): int => $this->locked($wallet->id, function (CreditWallet $w) use ($grantId, $maxMc, $idempotencyKey, $reason, $actorId): int {
            $done = CreditLedgerEntry::query()->where('idempotency_key', 'reversal:'.$idempotencyKey)->first();
            if ($done !== null) {
                return -$done->amount_mc;
            }
            $grant = CreditGrant::query()->whereKey($grantId)->lockForUpdate()->first();
            if ($grant === null) {
                return 0;
            }
            $free = max(0, min($grant->remaining_mc, $w->balance_mc - $w->held_mc));
            $amount = min($maxMc, $free);
            if ($amount <= 0) {
                return 0;
            }
            $grant->forceFill(['remaining_mc' => $grant->remaining_mc - $amount])->save();
            $orig = CreditLedgerEntry::query()->where('idempotency_key', 'grant:'.$grant->source_ref)->value('id');
            $this->entry($w, 'reversal', -$amount, 'reversal:'.$idempotencyKey, $grant->id, 'refund', $idempotencyKey, $reason, $actorId, $orig !== null ? (int) $orig : null);
            $w->save();

            return $amount;
        }));
    }

    /**
     * Expire the remainder of grants past their expiry (scheduler).
     */
    public function expireGrants(int $limit = 500): int
    {
        $count = 0;
        DB::table('credit_grants')->where('remaining_mc', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->whereNull('expired_at')->orderBy('expires_at')->limit($limit)->get(['id', 'workspace_id', 'wallet_id'])
            ->each(function (object $g) use (&$count): void {
                InTenant::run((string) $g->workspace_id, fn () => $this->locked((string) $g->wallet_id, function (CreditWallet $w) use ($g, &$count): void {
                    $grant = CreditGrant::query()->whereKey($g->id)->lockForUpdate()->first();
                    if ($grant === null || $grant->expired_at !== null || $grant->remaining_mc <= 0) {
                        return;
                    }
                    $amount = $grant->remaining_mc;
                    $grant->forceFill(['remaining_mc' => 0, 'expired_at' => now()])->save();
                    $this->entry($w, 'expire', -$amount, 'expire:'.$grant->id, $grant->id, 'grant', $grant->id, 'expired '.$grant->source, null);
                    $w->save();
                    $count++;
                }));
            });

        return $count;
    }

    /**
     * Settle reservations whose call outcome is unknown (worker crashed): at
     * the estimate, flagged for review — never assumed free.
     */
    public function expireStaleReservations(int $limit = 500): int
    {
        $count = 0;
        DB::table('credit_reservations')->where('status', 'held')->where('expires_at', '<', now())
            ->orderBy('expires_at')->limit($limit)->pluck('idempotency_key')
            ->each(function (string $key) use (&$count): void {
                if ($this->settle($key, null, 'expired')) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Ledger ↔ projection consistency check. Returns mismatching wallets.
     *
     * @return list<array{workspace_id: string, balance_mc: int, ledger_mc: int, grants_mc: int, held_mc: int, active_holds_mc: int}>
     */
    public function verify(): array
    {
        $ledger = DB::table('credit_ledger_entries')->selectRaw('wallet_id, SUM(amount_mc) AS s')->groupBy('wallet_id')->pluck('s', 'wallet_id');
        $grants = DB::table('credit_grants')->selectRaw('wallet_id, SUM(remaining_mc) AS s')->groupBy('wallet_id')->pluck('s', 'wallet_id');
        $holds = DB::table('credit_reservations')->where('status', 'held')->selectRaw('wallet_id, SUM(amount_mc) AS s')->groupBy('wallet_id')->pluck('s', 'wallet_id');
        $bad = [];
        foreach (DB::table('credit_wallets')->get(['id', 'workspace_id', 'balance_mc', 'held_mc']) as $w) {
            $l = (int) ($ledger[$w->id] ?? 0);
            $g = (int) ($grants[$w->id] ?? 0);
            $h = (int) ($holds[$w->id] ?? 0);
            $balance = (int) $w->balance_mc;
            $grantsOk = $balance >= 0 ? $g === $balance : $g === 0;
            if ($l !== $balance || ! $grantsOk || $h !== (int) $w->held_mc) {
                $bad[] = ['workspace_id' => (string) $w->workspace_id, 'balance_mc' => $balance, 'ledger_mc' => $l, 'grants_mc' => $g, 'held_mc' => (int) $w->held_mc, 'active_holds_mc' => $h];
            }
        }

        return $bad;
    }

    /**
     * Debit `amount` from grants earliest-expiry first; remainder (approved
     * overdraft) is booked without a grant. Returns the first entry id.
     */
    private function consume(CreditWallet $w, int $amount, string $type, string $keyPrefix, string $refType, string $refId, ?string $description, ?string $actorId): ?int
    {
        if ($amount <= 0) {
            return null;
        }
        $left = $amount;
        $first = null;
        $i = 0;
        $grants = CreditGrant::query()->where('wallet_id', $w->id)->where('remaining_mc', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('created_at')->orderBy('id')
            ->lockForUpdate()->get();
        foreach ($grants as $grant) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $grant->remaining_mc);
            $grant->forceFill(['remaining_mc' => $grant->remaining_mc - $take])->save();
            $id = $this->entry($w, $type, -$take, $keyPrefix.':'.$i++, $grant->id, $refType, $refId, $description, $actorId);
            $first ??= $id;
            $left -= $take;
        }
        if ($left > 0) {
            $id = $this->entry($w, $type, -$left, $keyPrefix.':'.$i, null, $refType, $refId, ($description ?? '').' (overdraft)', $actorId);
            $first ??= $id;
        }

        return $first;
    }

    private function entry(CreditWallet $w, string $type, int $amount, string $key, ?string $grantId, ?string $refType, ?string $refId, ?string $description, ?string $actorId, ?int $reverses = null): int
    {
        $w->balance_mc += $amount;
        $e = new CreditLedgerEntry;
        $e->forceFill([
            'wallet_id' => $w->id, 'grant_id' => $grantId, 'entry_type' => $type, 'amount_mc' => $amount,
            'balance_after_mc' => $w->balance_mc, 'idempotency_key' => mb_substr($key, 0, 191),
            'reference_type' => $refType, 'reference_id' => $refId !== null ? mb_substr($refId, 0, 64) : null,
            'reverses_entry_id' => $reverses, 'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'actor_id' => $actorId, 'created_at' => now(),
        ])->save();
        $w->save();

        return $e->id;
    }

    /**
     * @template T
     *
     * @param  Closure(CreditWallet): T  $callback
     * @return T
     */
    private function locked(string $walletId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($walletId, $callback): mixed {
            $w = CreditWallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();

            return $callback($w);
        }, 5);
    }
}
