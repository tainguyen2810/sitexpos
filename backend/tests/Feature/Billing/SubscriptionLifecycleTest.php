<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

beforeEach(function () {
    Notification::fake();
    billingPlan('free', 0, null, includedMc: 10_000, free: true);
    billingPlan('basic', 200_000, 2_000_000, includedMc: 100_000, trialDays: 7, trialMc: 20_000);
    billingPlan('pro', 500_000, 5_000_000, includedMc: 300_000);
    billingPolicy();
    billingProvider();
    $this->t = workspaceWithRoles('Life');
});

function pay(array $order): void
{
    signedWebhook(['event_id' => (string) Str::ulid(), 'transactions' => [bankTx((string) Str::ulid(), (int) $order['amount_minor'], $order['reference'])]])
        ->assertOk()->assertJsonPath('data.results.0.match_status', 'matched');
}

function subscribe(array $t, string $plan, string $interval = 'month'): array
{
    $order = test()->actingAs($t['owner'])->postJson(billingUrl($t['workspace'], 'subscription/checkout'), ['plan_code' => $plan, 'interval' => $interval])->assertCreated()->json('data');
    pay($order);

    return test()->actingAs($t['owner'])->getJson(billingUrl($t['workspace'], 'subscription'))->json('data.subscription');
}

it('gives one trial per person — a new workspace of the same owner is not eligible', function () {
    billingSettings(['trial_enabled' => true]);
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/trial'), ['plan_code' => 'basic'])
        ->assertCreated()->assertJsonPath('data.status', 'TRIALING');
    expect(DB::table('credit_grants')->where('workspace_id', $this->t['workspace']->id)->where('source', 'trial')->value('amount_mc'))->toEqual(20_000);

    $second = createWorkspace($this->t['owner'], 'Second');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($second, 'subscription/trial'), ['plan_code' => 'basic'])
        ->assertStatus(422)->assertJsonPath('error.details.reason', 'trial_already_used');

    // Gmail dot/plus alias of the same mailbox is the same person.
    $alias = User::factory()->create(['email' => 'abuser+2@gmail.com']);
    $orig = User::factory()->create(['email' => 'a.buser@gmail.com']);
    $w1 = createWorkspace($orig, 'A');
    $w2 = createWorkspace($alias, 'B');
    $this->actingAs($orig)->postJson(billingUrl($w1, 'subscription/trial'), ['plan_code' => 'basic'])->assertCreated();
    $this->actingAs($alias)->postJson(billingUrl($w2, 'subscription/trial'), ['plan_code' => 'basic'])->assertStatus(422);
});

it('ends a trial without charging when nobody paid (no auto-debit)', function () {
    billingSettings(['trial_enabled' => true]);
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/trial'), ['plan_code' => 'basic'])->assertCreated();

    $this->travel(8)->days();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();

    expect(DB::table('subscriptions')->where('workspace_id', $this->t['workspace']->id)->value('status'))->toBe('EXPIRED')
        ->and(DB::table('payment_orders')->count())->toBe(0);
});

it('refuses a trial when trials are disabled and a paid plan through the free endpoint', function () {
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/trial'), ['plan_code' => 'basic'])
        ->assertStatus(422)->assertJsonPath('error.details.reason', 'trial_disabled');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/free'), ['plan_code' => 'basic'])
        ->assertStatus(422)->assertJsonPath('error.code', 'payment_required');
});

it('upgrades immediately after payment with proration, keeping existing credits', function () {
    billingSettings(['proration_enabled' => true]);
    $sub = subscribe($this->t, 'basic');
    $this->travel(15)->days();

    $quote = $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/quote'), ['plan_code' => 'pro', 'interval' => 'month'])
        ->assertOk()->json('data');
    expect($quote['kind'])->toBe('upgrade')->and($quote['amount_minor'])->toBeGreaterThan(0)->toBeLessThan(300_000);

    $res = $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/change'), ['plan_code' => 'pro', 'interval' => 'month'])
        ->assertCreated()->json('data');
    expect($res['change']['status'])->toBe('pending_payment')->and($res['order']['amount_minor'])->toBe($quote['amount_minor']);
    // Not applied before payment.
    $this->getJson(billingUrl($this->t['workspace'], 'subscription'))->assertJsonPath('data.subscription.plan.code', 'basic');

    pay($res['order']);
    $after = $this->getJson(billingUrl($this->t['workspace'], 'subscription'))->json('data');
    expect($after['subscription']['plan']['code'])->toBe('pro')
        ->and($after['subscription']['id'])->toBe($sub['id'])
        ->and($after['subscription']['current_period_end'])->toBe($sub['current_period_end'])
        ->and($after['wallet']['balance_mc'])->toBeGreaterThan(100_000);
});

it('schedules a downgrade for the period end and applies it at renewal', function () {
    $sub = subscribe($this->t, 'pro');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/change'), ['plan_code' => 'basic', 'interval' => 'month'])
        ->assertCreated()->assertJsonPath('data.change.status', 'scheduled')->assertJsonPath('data.order', null);
    $this->getJson(billingUrl($this->t['workspace'], 'subscription'))->assertJsonPath('data.subscription.plan.code', 'pro')
        ->assertJsonPath('data.pending_change.kind', 'downgrade');

    $renewal = $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/renew'), [])->assertCreated()->json('data');
    expect($renewal['amount_minor'])->toBe(200_000); // the renewal is priced at the scheduled (lower) plan
    pay($renewal);

    $after = $this->getJson(billingUrl($this->t['workspace'], 'subscription'))->json('data.subscription');
    expect($after['plan']['code'])->toBe('basic')->and($after['current_period_start'])->toBe($sub['current_period_end']);
});

it('moves an unpaid subscription to grace, then expires it without deleting data', function () {
    billingSettings(['grace_days' => 3]);
    subscribe($this->t, 'basic');
    $site = DB::table('workspaces')->where('id', $this->t['workspace']->id)->exists();

    $this->travel(32)->days();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();
    expect(DB::table('subscriptions')->where('workspace_id', $this->t['workspace']->id)->value('status'))->toBe('GRACE_PERIOD');
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();
    expect(DB::table('billing_notifications')->where('type', 'renewal_due')->count())->toBe(1); // deduplicated

    $this->travel(4)->days();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();
    expect(DB::table('subscriptions')->where('workspace_id', $this->t['workspace']->id)->value('status'))->toBe('EXPIRED')
        ->and($site)->toBeTrue()
        ->and(DB::table('credit_wallets')->where('workspace_id', $this->t['workspace']->id)->exists())->toBeTrue();

    // The customer can subscribe again (the live slot was freed).
    subscribe($this->t, 'basic');
    $this->getJson(billingUrl($this->t['workspace'], 'subscription'))->assertJsonPath('data.subscription.status', 'ACTIVE');
});

it('cancels at period end, can resume before it, and keeps the history', function () {
    subscribe($this->t, 'basic');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/cancel'), ['reason' => 'Thử nghiệm'])
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/resume'))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/cancel'), [])->assertOk();

    $this->travel(32)->days();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();
    expect(DB::table('subscriptions')->where('workspace_id', $this->t['workspace']->id)->value('status'))->toBe('EXPIRED');

    $events = $this->actingAs($this->t['viewer'])->getJson(billingUrl($this->t['workspace'], 'subscription/history'))->assertOk()->json('data');
    expect(collect($events)->pluck('event')->all())->toContain('activated', 'cancelled', 'resumed', 'cancelled_period_ended');
});

it('sends each renewal reminder once', function () {
    subscribe($this->t, 'basic');
    $this->travel(24)->days();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();

    expect(DB::table('billing_notifications')->where('type', 'expiring_soon')->count())->toBe(1); // the 7-day mark
});

it('lets platform staff pause, suspend and extend with an audit trail; customers cannot', function () {
    $sub = subscribe($this->t, 'basic');
    $staff = User::factory()->platform(PlatformRole::BillingAdmin)->create();

    $this->actingAs($staff)->postJson('/api/admin/v1/billing/subscriptions/'.$sub['id'].'/actions', ['action' => 'extend', 'days' => 10, 'reason' => 'Bồi thường sự cố'])
        ->assertOk();
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/subscriptions/'.$sub['id'].'/actions', ['action' => 'suspend', 'reason' => 'Nghi gian lận thanh toán'])
        ->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
    expect(DB::table('audit_logs')->where('action', 'like', 'billing.subscription_%')->count())->toBeGreaterThanOrEqual(2);

    $this->actingAs($this->t['owner'])->postJson('/api/admin/v1/billing/subscriptions/'.$sub['id'].'/actions', ['action' => 'unsuspend', 'reason' => 'tự mở khoá'])
        ->assertNotFound();
    $this->actingAs(User::factory()->platform(PlatformRole::Support)->create())
        ->postJson('/api/admin/v1/billing/subscriptions/'.$sub['id'].'/actions', ['action' => 'unsuspend', 'reason' => 'tự mở khoá'])->assertForbidden();
});

it('refuses to delete a plan that is in use', function () {
    subscribe($this->t, 'basic');
    $planId = DB::table('plans')->where('code', 'basic')->value('id');
    $super = User::factory()->platform(PlatformRole::SuperAdmin)->create();

    $this->actingAs($super)->deleteJson('/api/admin/v1/billing/plans/'.$planId)->assertStatus(409)->assertJsonPath('error.code', 'plan_in_use');
});

it('publishes a new price version without changing existing subscriptions', function () {
    $sub = subscribe($this->t, 'basic');
    $planId = DB::table('plans')->where('code', 'basic')->value('id');
    $super = User::factory()->platform(PlatformRole::SuperAdmin)->create();

    $v = $this->actingAs($super)->postJson('/api/admin/v1/billing/plans/'.$planId.'/versions', ['currency' => 'VND', 'monthly_price_minor' => 250_000, 'entitlements' => ['websites' => 5]])
        ->assertCreated()->json('data');
    $this->actingAs($super)->postJson('/api/admin/v1/billing/plan-versions/'.$v['id'].'/publish')->assertOk();
    $this->actingAs($super)->patchJson('/api/admin/v1/billing/plan-versions/'.$v['id'], ['monthly_price_minor' => 1])->assertStatus(409);

    $this->actingAs($this->t['owner'])->getJson(billingUrl($this->t['workspace'], 'subscription'))
        ->assertJsonPath('data.subscription.price_minor', 200_000)->assertJsonPath('data.subscription.plan_version.id', $sub['plan_version']['id']);
    $prices = collect($this->getJson(billingUrl($this->t['workspace'], 'plans'))->json('data.plans'))->firstWhere('code', 'basic');
    expect($prices['current_version']['monthly_price_minor'])->toBe(250_000)->and($prices['current_version']['entitlements']['websites']['limit'] ?? $prices['current_version']['entitlements']['websites'] ?? null)->not->toBeNull();
});
