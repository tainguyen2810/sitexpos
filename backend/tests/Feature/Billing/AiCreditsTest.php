<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Contracts\UsageMeter;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\Billing\Credits\CreditLedger;
use SiteXPost\Modules\Billing\Domain\CreditLedgerEntry;
use SiteXPost\Modules\Billing\Domain\CreditReservation;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Workspace\Domain\Workspace;

beforeEach(function () {
    Notification::fake();
    seedAi();
    Sleep::fake();
    Http::preventStrayRequests();
    routeTask('system.test', ['fake/fake-echo']);
    billingPlan('free', 0, null, free: true);
    billingPolicy(microUsdPerCredit: 1000, markupBps: 5000); // +50 % markup
    billingSettings(['enforcement' => 'enforce', 'low_credit_threshold_mc' => 0]);
    $this->t = workspaceWithRoles('AI');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/free'), ['plan_code' => 'free'])->assertCreated();
});

function billedAi(Workspace $ws, string $prompt, string $key): mixed
{
    return app(TenantContext::class)->runAs($ws, null, fn () => app(AiGateway::class)->execute(new AiTask(taskKey: 'system.test', prompt: $prompt, callKey: $key)));
}

function reservations(Workspace $ws): Collection
{
    return app(TenantContext::class)->runAs($ws, null, fn () => CreditReservation::query()->orderBy('created_at')->get());
}

function walletOf(Workspace $ws): array
{
    return app(CreditLedger::class)->summary($ws->id);
}

it('blocks AI calls when the workspace has no credits (no provider call is made)', function () {
    expect(fn () => billedAi($this->t['workspace'], 'xin chào', 'c-0'))
        ->toThrow(fn (AiGatewayException $e) => expect($e->errorCode)->toBe('insufficient_credits'));

    expect(reservations($this->t['workspace']))->toHaveCount(0)
        ->and(DB::table('ai_requests')->where('workspace_id', $this->t['workspace']->id)->value('status'))->toBe('blocked');
});

it('reserves, settles the actual cost with markup and never charges a retried call twice', function () {
    app(CreditLedger::class)->grant($this->t['workspace']->id, 'promotional', 1_000_000, null, 'test:seed', 0, 'VND', 'seed', null);

    $result = billedAi($this->t['workspace'], 'Xin chào SiteX', 'c-1');
    $r = reservations($this->t['workspace'])->sole();
    $expected = intdiv($result->costMicroUsd * 15000 * 1000 + 10_000 * 1000 - 1, 10_000 * 1000);

    expect($r->status)->toBe('settled')
        ->and($r->charged_mc)->toBe($expected)
        ->and($r->category)->toBe('other')
        ->and(walletOf($this->t['workspace']))->toMatchArray(['balance_mc' => 1_000_000 - $expected, 'held_mc' => 0]);

    // A retried step with the same call key is refused by the gateway → no second call, no second charge.
    expect(fn () => billedAi($this->t['workspace'], 'Xin chào SiteX', 'c-1'))
        ->toThrow(fn (AiGatewayException $e) => expect($e->errorCode)->toBe('duplicate_call'));
    // Settling the same key again is a no-op.
    expect(app(UsageMeter::class)->settle('c-1:1', 999_999))->toBeFalse();
    expect(reservations($this->t['workspace']))->toHaveCount(1)
        ->and(walletOf($this->t['workspace'])['balance_mc'])->toBe(1_000_000 - $expected);

    $this->artisan('sitex:billing:verify-ledger')->assertSuccessful();
});

it('charges an uncertain provider timeout at the estimate and flags it for review', function () {
    app(CreditLedger::class)->grant($this->t['workspace']->id, 'promotional', 5_000_000, null, 'test:seed', 0, 'VND', 'seed', null);
    config(['sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000']);
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms'));
    routeTask('system.test', ['openai/gpt-6-luna']);

    expect(fn () => billedAi($this->t['workspace'], 'hello', 'c-2'))->toThrow(AiGatewayException::class);

    $r = reservations($this->t['workspace'])->sole();
    expect($r->status)->toBe('uncertain')
        ->and($r->needs_review)->toBeTrue()
        ->and($r->charged_mc)->toBeGreaterThan(0)
        ->and($r->actual_micro_usd)->toBe($r->estimate_micro_usd);

    $staff = User::factory()->platform(PlatformRole::BillingAdmin)->create();
    $this->actingAs($staff)->getJson('/api/admin/v1/billing/uncertain-usage')->assertOk()->assertJsonPath('data.0.id', $r->id);
});

it('releases the hold when a provider fails before any usage', function () {
    app(CreditLedger::class)->grant($this->t['workspace']->id, 'promotional', 1_000_000, null, 'test:seed', 0, 'VND', 'seed', null);

    expect(fn () => billedAi($this->t['workspace'], '[fail:refused] x', 'c-3'))->toThrow(AiGatewayException::class);

    expect(reservations($this->t['workspace'])->sole()->status)->toBeIn(['released', 'settled'])
        ->and(walletOf($this->t['workspace'])['held_mc'])->toBe(0);
});

it('settles a hold left by a crashed worker at its estimate (not free) and only once', function () {
    $ledger = app(CreditLedger::class);
    $ledger->grant($this->t['workspace']->id, 'promotional', 1_000_000, null, 'test:seed', 0, 'VND', 'seed', null);
    [$r] = $ledger->reserve($this->t['workspace']->id, 'crash-1', 'writer.draft', 'writing', 20_000);
    expect(walletOf($this->t['workspace'])['held_mc'])->toBe(30_000);

    $this->travel(31)->minutes();
    $this->artisan('sitex:billing:expire-credits')->assertSuccessful();
    // A late settle from the "dead" worker is ignored.
    expect($ledger->settle('crash-1', 1))->toBeFalse();

    $r = reservations($this->t['workspace'])->sole();
    expect($r->status)->toBe('expired')->and($r->charged_mc)->toBe(30_000)
        ->and(walletOf($this->t['workspace']))->toMatchArray(['balance_mc' => 970_000, 'held_mc' => 0]);
});

it('never lets the balance go negative: uncovered cost is recorded as uncollected', function () {
    $ledger = app(CreditLedger::class);
    $ledger->grant($this->t['workspace']->id, 'promotional', 10_000, null, 'test:seed', 0, 'VND', 'seed', null);
    $ledger->reserve($this->t['workspace']->id, 'big-1', 'writer.draft', 'writing', 1_000); // holds 1 500 mc
    $ledger->settle('big-1', 100_000); // actual was far above the estimate: 150 000 mc

    $r = reservations($this->t['workspace'])->sole();
    expect(walletOf($this->t['workspace'])['balance_mc'])->toBe(0)
        ->and($r->charged_mc)->toBe(10_000)
        ->and($r->uncollected_mc)->toBe(140_000);
    $this->artisan('sitex:billing:verify-ledger')->assertSuccessful();
});

it('consumes credits earliest-expiry first and expires the rest', function () {
    $ledger = app(CreditLedger::class);
    $ws = $this->t['workspace']->id;
    $ledger->grant($ws, 'purchased', 50_000, null, 'test:forever', 0, 'VND', 'non-expiring', null);
    $ledger->grant($ws, 'bonus', 20_000, now()->addDay(), 'test:soon', 0, 'VND', 'expiring', null);
    $ledger->reserve($ws, 'k', 'writer.draft', 'writing', 10_000);
    $ledger->settle('k', 10_000); // 15 000 mc → all taken from the expiring bucket

    $this->travel(2)->days();
    $this->artisan('sitex:billing:expire-credits')->assertSuccessful();

    expect(walletOf($this->t['workspace'])['balance_mc'])->toBe(50_000)
        ->and(DB::table('credit_ledger_entries')->where('workspace_id', $ws)->where('entry_type', 'expire')->sum('amount_mc'))->toEqual(-5_000);
    $this->artisan('sitex:billing:verify-ledger')->assertSuccessful();
});

it('keeps the ledger append-only', function () {
    app(CreditLedger::class)->grant($this->t['workspace']->id, 'promotional', 1_000, null, 'test:seed', 0, 'VND', 'seed', null);
    $entry = app(TenantContext::class)->runAs($this->t['workspace'], null, fn () => CreditLedgerEntry::query()->firstOrFail());

    expect(fn () => $entry->forceFill(['amount_mc' => 999_999])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
