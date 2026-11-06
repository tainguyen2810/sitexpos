<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchUsageCounter;

/**
 * Research quotas with reservation (interim entitlement until plans/billing):
 * a run reserves 1 "run" when it is queued (row lock on the monthly counter,
 * so concurrent starts cannot overshoot), commits it when it ends with
 * results, releases it when it fails or is canceled. AI money is guarded
 * separately by the AI Gateway budgets + the per-run cost cap.
 */
final class QuotaManager
{
    public function __construct(private readonly ResearchSettings $settings) {}

    /**
     * Reserve one run and create it in the same transaction: the monthly
     * counter row lock serialises concurrent starts of a workspace, so the
     * concurrency and monthly checks cannot be raced.
     *
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     *
     * @throws ApiException 429 research_quota_exceeded / research_concurrency_limit
     */
    public function reserveRun(string $workspaceId, callable $create): mixed
    {
        $limits = $this->settings->limitsFor($workspaceId);
        // Phase 7: the plan (when billing applies) decides; interim limits otherwise.
        $entitlements = app(Entitlements::class);
        $entitlements->assertActive($workspaceId, 'research');
        $planRuns = $entitlements->limit($workspaceId, 'research_runs');
        if ($planRuns !== null) {
            $limits['max_runs_per_month'] = $planRuns;
        }
        $this->counter('runs', false); // make sure the row exists before locking

        return DB::transaction(function () use ($limits, $create): mixed {
            $counter = $this->counter('runs', true);
            $active = ResearchRun::query()->whereIn('status', ResearchRun::ACTIVE)->count();
            if ($active >= $limits['max_concurrent_runs']) {
                throw new ApiException(429, 'research_concurrency_limit', __('research.errors.concurrency_limit', ['max' => $limits['max_concurrent_runs']]));
            }
            if ($counter->used + $counter->reserved >= $limits['max_runs_per_month']) {
                throw new ApiException(429, 'research_quota_exceeded', __('research.errors.quota_exceeded', ['max' => $limits['max_runs_per_month']]));
            }
            $counter->forceFill(['reserved' => $counter->reserved + 1])->save();

            return $create();
        });
    }

    public function settle(ResearchRun $run, bool $consumed): void
    {
        DB::transaction(function () use ($run, $consumed): void {
            $fresh = ResearchRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->quota_reserved) {
                return;
            }
            $counter = $this->counter('runs', true, $fresh->created_at?->format('Y-m'));
            $counter->forceFill([
                'reserved' => max(0, $counter->reserved - 1),
                'used' => $counter->used + ($consumed ? 1 : 0),
            ])->save();
            $fresh->forceFill(['quota_reserved' => false])->save();
            $run->quota_reserved = false;
        });
    }

    public function addUsage(string $metric, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        DB::transaction(function () use ($metric, $amount): void {
            $c = $this->counter($metric, true);
            $c->forceFill(['used' => $c->used + $amount])->save();
        });
    }

    /**
     * @return array<string, array{used: int, reserved: int, limit: int|null}>
     */
    public function usage(string $workspaceId): array
    {
        $limits = $this->settings->limitsFor($workspaceId);
        $period = now()->format('Y-m');
        $rows = ResearchUsageCounter::query()->where('period', $period)->get()->keyBy('metric');

        return [
            'runs' => ['used' => (int) ($rows->get('runs')->used ?? 0), 'reserved' => (int) ($rows->get('runs')->reserved ?? 0), 'limit' => $limits['max_runs_per_month']],
            'ideas' => ['used' => (int) ($rows->get('ideas')->used ?? 0), 'reserved' => 0, 'limit' => null],
            'ai_cost_micro_usd' => ['used' => (int) ($rows->get('ai_cost_micro_usd')->used ?? 0), 'reserved' => 0, 'limit' => null],
        ];
    }

    private function counter(string $metric, bool $lock, ?string $period = null): ResearchUsageCounter
    {
        $period ??= now()->format('Y-m');
        $find = fn () => ResearchUsageCounter::query()->where(['period' => $period, 'metric' => $metric])->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $row = $find();
        if ($row === null) {
            try {
                $row = new ResearchUsageCounter;
                $row->forceFill(['period' => $period, 'metric' => $metric, 'used' => 0, 'reserved' => 0])->save();
            } catch (QueryException) {
                $row = $find();
            }
        }

        return $row ?? throw new \RuntimeException('usage counter unavailable');
    }
}
