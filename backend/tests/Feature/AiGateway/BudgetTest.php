<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetCounter;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetReservation;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\AiGateway\Gateway\BudgetExceeded;
use SiteXPost\Modules\AiGateway\Gateway\BudgetManager;

beforeEach(function () {
    seedAi();
    Sleep::fake();
    $this->ws = createWorkspace();
    $this->budgets = app(BudgetManager::class);
});

it('reserves, commits the real cost and releases the rest', function () {
    aiBudget($this->ws->id, 10_000);

    $this->budgets->reserve('k1', $this->ws->id, 4_000);
    $counter = AiBudgetCounter::query()->sole();
    expect($counter->reserved_micro_usd)->toBe(4_000)->and($counter->spent_micro_usd)->toBe(0);

    expect($this->budgets->commit('k1', 1_500))->toBeTrue();
    $counter->refresh();
    expect($counter->reserved_micro_usd)->toBe(0)->and($counter->spent_micro_usd)->toBe(1_500);
});

it('never charges the same reservation twice', function () {
    aiBudget($this->ws->id, 10_000);

    $first = $this->budgets->reserve('dup', $this->ws->id, 3_000);
    $again = $this->budgets->reserve('dup', $this->ws->id, 3_000);

    expect($again->id)->toBe($first->id)
        ->and(AiBudgetCounter::query()->sole()->reserved_micro_usd)->toBe(3_000)
        ->and($this->budgets->commit('dup', 2_000))->toBeTrue()
        ->and($this->budgets->commit('dup', 2_000))->toBeFalse()
        ->and($this->budgets->release('dup'))->toBeFalse()
        ->and(AiBudgetCounter::query()->sole()->spent_micro_usd)->toBe(2_000);
});

it('blocks a reservation that would exceed the limit, counting in-flight reservations', function () {
    aiBudget($this->ws->id, 5_000);

    $this->budgets->reserve('a', $this->ws->id, 3_000);
    expect(fn () => $this->budgets->reserve('b', $this->ws->id, 2_500))->toThrow(BudgetExceeded::class);

    $this->budgets->release('a');
    expect($this->budgets->reserve('b', $this->ws->id, 2_500)->status)->toBe('reserved');
});

it('pause_batch blocks batch work but lets interactive work through', function () {
    aiBudget($this->ws->id, 1_000, 'pause_batch');

    expect(fn () => $this->budgets->reserve('batch', $this->ws->id, 2_000, 'batch'))->toThrow(BudgetExceeded::class);
    expect($this->budgets->reserve('ui', $this->ws->id, 2_000, 'interactive')->status)->toBe('reserved');
});

it('applies the global system budget to every tenant', function () {
    $other = createWorkspace();
    aiBudget(null, 5_000);

    $this->budgets->reserve('a', $this->ws->id, 4_000);
    expect(fn () => $this->budgets->reserve('b', $other->id, 2_000))->toThrow(fn (BudgetExceeded $e) => expect($e->scopeKey)->toBe('global'));
});

it('commits expired reservations at their estimate (worker died mid-call)', function () {
    aiBudget($this->ws->id, 10_000);
    $this->budgets->reserve('lost', $this->ws->id, 2_000);

    $this->travel(31)->minutes();
    expect($this->budgets->expireStale())->toBe(1);

    $res = AiBudgetReservation::query()->sole();
    expect($res->status)->toBe('expired')->and($res->actual_micro_usd)->toBe(2_000)
        ->and(AiBudgetCounter::query()->sole()->spent_micro_usd)->toBe(2_000)
        ->and(AiBudgetCounter::query()->sole()->reserved_micro_usd)->toBe(0);

    $this->artisan('sitex:ai:expire-reservations')->assertSuccessful();
});

it('starts a mid-period budget from what was already spent', function () {
    routeTask('system.test', ['fake/fake-echo']);
    app(TenantContext::class)->runAs($this->ws, null, fn () => app(AiGateway::class)->execute(new AiTask('system.test', 'hello world', 'pre-1')));
    $spent = app(TenantContext::class)->runAs($this->ws, null, fn () => (int) AiRequest::query()->sum('cost_micro_usd'));

    $budget = aiBudget($this->ws->id, 1_000_000);
    expect($spent)->toBeGreaterThan(0)
        ->and($this->budgets->status($budget)['spent_micro_usd'])->toBe($spent);
});

it('blocks the gateway call when the budget is exhausted and logs it', function () {
    routeTask('system.test', ['fake/fake-echo']);
    aiBudget($this->ws->id, 1); // 1 micro-USD

    try {
        app(TenantContext::class)->runAs($this->ws, null, fn () => app(AiGateway::class)->execute(new AiTask('system.test', 'hello', 'blocked-1')));
        $this->fail('expected budget block');
    } catch (AiGatewayException $e) {
        expect($e->errorCode)->toBe('budget_exceeded')->and($e->retryable)->toBeFalse();
    }

    $row = app(TenantContext::class)->runAs($this->ws, null, fn () => AiRequest::query()->sole());
    expect($row->status)->toBe('blocked')->and($row->cost_micro_usd)->toBe(0);
});

it('routes to the economy model when a budget passes its alert threshold', function () {
    $route = routeTask('system.test', ['fake/fake-echo'], ['economy_model_id' => aiModel('fake/fake-echo')->id]);
    $primary = aiModel('openai/gpt-6-luna');
    $route->forceFill(['primary_model_id' => $primary->id, 'fallback_model_ids' => []])->save();
    aiBudget($this->ws->id, 1_000, 'alert', alertPct: 50);
    $this->budgets->reserve('warm', $this->ws->id, 600);
    $this->budgets->commit('warm', 600);

    $result = app(TenantContext::class)->runAs($this->ws, null, fn () => app(AiGateway::class)->execute(new AiTask('system.test', 'hi', 'eco-1')));

    expect($result->modelKey)->toBe('fake/fake-echo')->and($result->downgraded)->toBeTrue();
});

/**
 * Real concurrency: N forked PHP processes reserve against the same MySQL
 * budget at the same time. Without row locks several would pass; with
 * SELECT … FOR UPDATE exactly floor(limit / amount) succeed.
 */
it('never oversells a budget under concurrent reservations (MySQL, forked processes)', function () {
    $ws = createWorkspace();
    aiBudget($ws->id, 10_000);
    // Make the rows visible to other connections: commit the test transaction.
    DB::commit();

    try {
        $workers = 8;
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                try {
                    app(BudgetManager::class)->reserve("race-{$i}", $ws->id, 3_000);
                    exit(0);
                } catch (BudgetExceeded) {
                    exit(3);
                } catch (Throwable $e) {
                    file_put_contents(sys_get_temp_dir()."/sitex-race-{$i}.log", $e::class.': '.$e->getMessage());
                    exit(1);
                }
            }
            $pids[] = $pid;
        }

        $codes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        DB::reconnect();

        $counts = array_count_values($codes);
        ksort($counts);
        expect($counts)->toBe([0 => 3, 3 => 5]) // 3 × 3 000 fit in 10 000; 5 are refused; 0 errors
            ->and(AiBudgetCounter::query()->sole()->reserved_micro_usd)->toBe(9_000)
            ->and(AiBudgetReservation::query()->count())->toBe(3);
    } finally {
        // RefreshDatabase cannot roll back committed rows: clean up.
        foreach (['ai_budget_reservations', 'ai_budget_counters', 'ai_budgets', 'audit_logs', 'workspace_members', 'workspaces', 'users'] as $t) {
            DB::table($t)->delete();
        }
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork'), 'needs MySQL + pcntl');
