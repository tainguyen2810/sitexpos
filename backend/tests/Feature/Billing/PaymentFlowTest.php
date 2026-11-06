<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use SiteXPost\Modules\Billing\Domain\CreditPackage;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

beforeEach(function () {
    Notification::fake();
    billingPlan('pro', 300_000, 3_000_000, includedMc: 100_000, limits: ['websites' => 3]);
    billingPolicy();
    billingProvider();
    $this->t = workspaceWithRoles('Shop');
});

function checkout(array $t, string $plan = 'pro', string $interval = 'month'): array
{
    return test()->actingAs($t['owner'])->postJson(billingUrl($t['workspace'], 'subscription/checkout'), ['plan_code' => $plan, 'interval' => $interval])
        ->assertCreated()->json('data');
}

function liveSubscription(array $t): ?object
{
    return DB::table('subscriptions')->where('workspace_id', $t['workspace']->id)->whereNotNull('live_key')->first();
}

it('creates a pending order with a VietQR transfer instruction and never trusts the client', function () {
    $order = checkout($this->t);

    expect($order['status'])->toBe('PENDING')
        ->and($order['amount_minor'])->toBe(300_000)
        ->and($order['reference'])->toMatch('/^SXP[A-HJ-NP-Z2-9]{8}$/')
        ->and($order['instructions']['memo'])->toBe($order['reference'])
        ->and($order['instructions']['qr_payload'])->toContain('300000')
        ->and(liveSubscription($this->t))->toBeNull();

    // "I have transferred" only marks PROCESSING — the frontend can never activate service.
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'orders/'.$order['id'].'/transferred'), ['status' => 'PAID'])
        ->assertOk()->assertJsonPath('data.status', 'PROCESSING');
    expect(liveSubscription($this->t))->toBeNull();
});

it('activates the subscription exactly once from a verified webhook and ignores replays', function () {
    $order = checkout($this->t);
    $body = ['event_id' => 'evt-1', 'transactions' => [bankTx('tx-1', 300_000, 'CK '.$order['reference'].' thanh toan')]];

    signedWebhook($body)->assertOk()->assertJsonPath('data.status', 'processed')->assertJsonPath('data.results.0.match_status', 'matched');
    signedWebhook($body)->assertOk()->assertJsonPath('data.status', 'duplicate');
    // Same bank transaction re-sent under a new event id → still one payment.
    signedWebhook(['event_id' => 'evt-2', 'transactions' => [bankTx('tx-1', 300_000, $order['reference'])]])->assertOk();

    $sub = liveSubscription($this->t);
    expect($sub->status)->toBe('ACTIVE')
        ->and(DB::table('payment_orders')->where('id', $order['id'])->value('status'))->toBe('PAID')
        ->and(DB::table('subscriptions')->where('workspace_id', $this->t['workspace']->id)->count())->toBe(1)
        ->and(DB::table('credit_grants')->where('workspace_id', $this->t['workspace']->id)->where('source', 'included')->sum('amount_mc'))->toEqual(100_000)
        ->and(DB::table('revenue_records')->where('payment_order_id', $order['id'])->count())->toBe(1)
        ->and(DB::table('billing_documents')->where('workspace_id', $this->t['workspace']->id)->where('type', 'receipt')->count())->toBe(1)
        ->and(DB::table('billing_notifications')->where('workspace_id', $this->t['workspace']->id)->where('type', 'payment_succeeded')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'billing.payment_confirmed')->count())->toBe(1);

    $this->actingAs($this->t['viewer'])->getJson(billingUrl($this->t['workspace'], 'subscription'))
        ->assertOk()->assertJsonPath('data.subscription.status', 'ACTIVE')->assertJsonPath('data.wallet.balance_mc', 100_000);
});

it('rejects forged, stale and unsigned webhooks without processing anything', function () {
    $order = checkout($this->t);
    $body = ['event_id' => 'evt-x', 'transactions' => [bankTx('tx-x', 300_000, $order['reference'])]];

    signedWebhook($body, secret: 'wrong-secret')->assertStatus(401)->assertJsonPath('error.code', 'webhook_rejected');
    signedWebhook($body, ts: time() - 3600)->assertStatus(401);
    $this->postJson('/api/v1/billing/webhooks/bankfeed', $body)->assertStatus(401);
    signedWebhook($body, code: 'unknown')->assertNotFound();

    expect(DB::table('payment_transactions')->count())->toBe(0)
        ->and(DB::table('payment_webhook_events')->count())->toBe(0)
        ->and(DB::table('payment_orders')->where('id', $order['id'])->value('status'))->toBe('PENDING');
});

it('sends underpaid, overpaid, wrong currency and unknown references to manual review', function () {
    $order = checkout($this->t);
    signedWebhook(['event_id' => 'e1', 'transactions' => [
        bankTx('u1', 299_000, $order['reference']),
        bankTx('o1', 400_000, $order['reference']),
        bankTx('c1', 300_000, $order['reference'], 'USD'),
        bankTx('n1', 300_000, 'chuyen tien khong ma'),
    ]])->assertOk()
        ->assertJsonPath('data.results.0.reason', 'underpaid')
        ->assertJsonPath('data.results.1.reason', 'overpaid')
        ->assertJsonPath('data.results.2.reason', 'currency_mismatch')
        ->assertJsonPath('data.results.3.match_status', 'unmatched');

    expect(DB::table('payment_orders')->where('id', $order['id'])->value('status'))->toBe('PENDING')
        ->and(liveSubscription($this->t))->toBeNull();
});

it('does not activate an expired order automatically, but staff can approve a verified late transfer', function () {
    $order = checkout($this->t);
    $this->travel(2)->days();
    $this->artisan('sitex:billing:lifecycle')->assertSuccessful();
    expect(DB::table('payment_orders')->where('id', $order['id'])->value('status'))->toBe('EXPIRED');

    signedWebhook(['event_id' => 'late', 'transactions' => [bankTx('late-1', 300_000, $order['reference'])]])
        ->assertJsonPath('data.results.0.reason', 'order_not_payable');
    expect(liveSubscription($this->t))->toBeNull();

    $txId = DB::table('payment_transactions')->where('external_id', 'late-1')->value('id');
    $this->actingAs(User::factory()->platform(PlatformRole::BillingAdmin)->create())
        ->postJson('/api/admin/v1/billing/transactions/'.$txId.'/approve', ['order_id' => $order['id'], 'note' => 'Đã kiểm tra sao kê ngân hàng'])
        ->assertOk();
    expect(liveSubscription($this->t)->status)->toBe('ACTIVE');
});

it('records a manual bank payment once (unique bank reference)', function () {
    $order = checkout($this->t);
    $staff = User::factory()->platform(PlatformRole::BillingAdmin)->create();
    $payload = ['bank_reference' => 'FT2611050001', 'amount_minor' => 300_000, 'note' => 'Khách chuyển khoản tại quầy'];

    $this->actingAs($staff)->postJson('/api/admin/v1/billing/orders/'.$order['id'].'/manual-payment', $payload)->assertCreated();
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/orders/'.$order['id'].'/manual-payment', $payload)->assertStatus(409);

    expect(DB::table('revenue_records')->where('payment_order_id', $order['id'])->count())->toBe(1)
        ->and(liveSubscription($this->t)->status)->toBe('ACTIVE');
});

it('tops up purchased + bonus credits once per paid order', function () {
    $p = new CreditPackage;
    $p->forceFill(['code' => 'c100', 'name' => 'Test 100', 'credits_mc' => 100_000, 'bonus_credits_mc' => 10_000, 'price_minor' => 50_000, 'currency' => 'VND', 'validity_days' => 365])->save();

    $order = $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'credits/purchase'), ['package_code' => 'c100', 'idempotency_key' => 'k1'])
        ->assertCreated()->json('data');
    // Double click with the same idempotency key → same order.
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'credits/purchase'), ['package_code' => 'c100', 'idempotency_key' => 'k1'])
        ->assertJsonPath('data.id', $order['id']);

    signedWebhook(['event_id' => 'p1', 'transactions' => [bankTx('p-1', 50_000, $order['reference'])]])->assertOk();
    signedWebhook(['event_id' => 'p2', 'transactions' => [bankTx('p-1', 50_000, $order['reference'])]])->assertOk();

    $this->actingAs($this->t['owner'])->getJson(billingUrl($this->t['workspace'], 'credits'))
        ->assertOk()->assertJsonPath('data.wallet.balance_mc', 110_000);
    $this->artisan('sitex:billing:verify-ledger')->assertSuccessful();
});

it('refunds: success reverses purchased credits and revenue; failure changes nothing', function () {
    $p = new CreditPackage;
    $p->forceFill(['code' => 'c100', 'name' => 'Test 100', 'credits_mc' => 100_000, 'price_minor' => 50_000, 'currency' => 'VND', 'validity_days' => null])->save();
    $order = $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'credits/purchase'), ['package_code' => 'c100'])->json('data');
    signedWebhook(['event_id' => 'r1', 'transactions' => [bankTx('r-1', 50_000, $order['reference'])]])->assertOk();

    $staff = User::factory()->platform(PlatformRole::BillingAdmin)->create();
    // More than paid is refused.
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/orders/'.$order['id'].'/refunds', ['amount_minor' => 60_000, 'reason' => 'Khách yêu cầu hoàn'])->assertStatus(422);

    $failed = $this->actingAs($staff)->postJson('/api/admin/v1/billing/orders/'.$order['id'].'/refunds', ['amount_minor' => 25_000, 'reason' => 'Khách yêu cầu hoàn'])->assertCreated()->json('data.id');
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/refunds/'.$failed.'/complete', ['succeeded' => false, 'failure_reason' => 'Sai số tài khoản'])->assertOk();
    expect(DB::table('payment_orders')->where('id', $order['id'])->value('status'))->toBe('PAID')
        ->and(DB::table('credit_wallets')->where('workspace_id', $this->t['workspace']->id)->value('balance_mc'))->toEqual(100_000);

    $ok = $this->actingAs($staff)->postJson('/api/admin/v1/billing/orders/'.$order['id'].'/refunds', ['amount_minor' => 25_000, 'reason' => 'Khách yêu cầu hoàn'])->assertCreated()->json('data.id');
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/refunds/'.$ok.'/complete', ['succeeded' => true, 'external_ref' => 'RF-BANK-1'])->assertOk();
    // Completing twice is a no-op / conflict, never a second reversal.
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/refunds/'.$ok.'/complete', ['succeeded' => true, 'external_ref' => 'RF-BANK-1']);

    expect(DB::table('payment_orders')->where('id', $order['id'])->value('status'))->toBe('PARTIALLY_REFUNDED')
        ->and(DB::table('credit_wallets')->where('workspace_id', $this->t['workspace']->id)->value('balance_mc'))->toEqual(50_000)
        ->and(DB::table('revenue_records')->where('payment_order_id', $order['id'])->sum('amount_minor'))->toEqual(25_000)
        ->and(DB::table('billing_documents')->where('type', 'refund_record')->count())->toBe(1);
    $this->artisan('sitex:billing:verify-ledger')->assertSuccessful();
});

it('lets only the owner create orders; members can view; other tenants get 404', function () {
    foreach (['admin', 'editor', 'viewer'] as $role) {
        $this->actingAs($this->t[$role])->postJson(billingUrl($this->t['workspace'], 'subscription/checkout'), ['plan_code' => 'pro', 'interval' => 'month'])->assertForbidden();
        $this->actingAs($this->t[$role])->getJson(billingUrl($this->t['workspace'], 'subscription'))->assertOk();
    }
    $order = checkout($this->t);

    $other = workspaceWithRoles('Other');
    $this->actingAs($other['owner'])->getJson(billingUrl($other['workspace'], 'orders/'.$order['id']))->assertNotFound();
    $this->actingAs($other['owner'])->postJson(billingUrl($other['workspace'], 'orders/'.$order['id'].'/transferred'))->assertNotFound();
    $this->actingAs($other['owner'])->getJson(billingUrl($this->t['workspace'], 'orders'))->assertNotFound();
});

it('refuses a workspace_id in the request body', function () {
    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/checkout'), ['plan_code' => 'pro', 'interval' => 'month', 'workspace_id' => 'x'])
        ->assertStatus(422);
});
