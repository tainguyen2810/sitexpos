<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

beforeEach(function () {
    Notification::fake();
    fakePublicDns();
    billingPlan('starter', 0, null, free: true, limits: ['websites' => 1, 'members' => 2], features: ['ai_images' => false]);
    billingPolicy();
    $this->t = workspaceWithRoles('Quota');
});

function addSite(array $t, string $host): TestResponse
{
    return test()->actingAs($t['owner'])->postJson('/api/v1/workspaces/'.$t['workspace']->id.'/sites', ['name' => $host, 'url' => 'https://'.$host]);
}

it('changes nothing while enforcement is off (Phase 1–6 behaviour kept)', function () {
    addSite($this->t, 'a.example.com')->assertCreated();
    addSite($this->t, 'b.example.com')->assertCreated();
    expect(app(Entitlements::class)->limit($this->t['workspace']->id, 'websites'))->toBeNull();
});

it('enforces plan limits in the backend once enabled', function () {
    billingSettings(['enforcement' => 'enforce']);
    // No subscription and no default plan → new paid work is refused, data stays readable.
    addSite($this->t, 'a.example.com')->assertStatus(402)->assertJsonPath('error.code', 'subscription_required');
    $this->actingAs($this->t['viewer'])->getJson('/api/v1/workspaces/'.$this->t['workspace']->id.'/sites')->assertOk();

    $this->actingAs($this->t['owner'])->postJson(billingUrl($this->t['workspace'], 'subscription/free'), ['plan_code' => 'starter'])->assertCreated();
    addSite($this->t, 'a.example.com')->assertCreated();
    addSite($this->t, 'b.example.com')->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached')
        ->assertJsonPath('error.details.resource', 'websites');

    // owner + admin + editor + viewer already exceed 2 members → invitations refused.
    $this->actingAs($this->t['owner'])->postJson('/api/v1/workspaces/'.$this->t['workspace']->id.'/invitations', ['email' => 'new@example.com', 'role' => 'EDITOR'])
        ->assertStatus(402);

    expect(fn () => app(Entitlements::class)->assertActive($this->t['workspace']->id, 'ai_images'))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('feature_unavailable'));
});

it('gives workspaces without a subscription the configured default plan', function () {
    billingSettings(['enforcement' => 'enforce', 'default_plan_code' => 'starter']);
    $e = app(Entitlements::class);

    expect($e->limit($this->t['workspace']->id, 'websites'))->toBe(1);
    addSite($this->t, 'a.example.com')->assertCreated();
    addSite($this->t, 'b.example.com')->assertStatus(402);
});

it('restricts the admin billing API to staff with platform.billing.manage', function () {
    $this->actingAs($this->t['owner'])->getJson('/api/admin/v1/billing/plans')->assertNotFound();
    $this->actingAs(User::factory()->platform(PlatformRole::Support)->create())->getJson('/api/admin/v1/billing/plans')->assertForbidden();
    $this->actingAs(User::factory()->platform(PlatformRole::Support)->create())
        ->postJson('/api/admin/v1/billing/workspaces/'.$this->t['workspace']->id.'/credit-adjustments', ['amount_mc' => 1_000_000, 'kind' => 'promotional', 'reason' => 'tặng thêm credits'])
        ->assertForbidden();
    $this->actingAs(User::factory()->platform(PlatformRole::BillingAdmin)->create())->getJson('/api/admin/v1/billing/plans')->assertOk();
    expect(DB::table('credit_ledger_entries')->count())->toBe(0);
});

it('records credit adjustments in the ledger with a reason and refuses a debit below zero', function () {
    $staff = User::factory()->platform(PlatformRole::BillingAdmin)->create();
    $url = '/api/admin/v1/billing/workspaces/'.$this->t['workspace']->id.'/credit-adjustments';

    $this->actingAs($staff)->postJson($url, ['amount_mc' => 50_000, 'kind' => 'promotional', 'reason' => 'Khuyến mãi khai trương'])
        ->assertCreated()->assertJsonPath('data.wallet.balance_mc', 50_000);
    $this->actingAs($staff)->postJson($url, ['amount_mc' => -80_000, 'kind' => 'correction', 'reason' => 'Điều chỉnh sai lệch'])
        ->assertStatus(422)->assertJsonPath('error.code', 'insufficient_credits');
    $this->actingAs($staff)->postJson($url, ['amount_mc' => 50_000, 'kind' => 'promotional', 'reason' => ''])->assertStatus(422);
    $this->actingAs($staff)->postJson('/api/admin/v1/billing/workspaces/01JZZZZZZZZZZZZZZZZZZZZZZZ/credit-adjustments', ['amount_mc' => 1, 'kind' => 'bonus', 'reason' => 'không tồn tại'])
        ->assertNotFound();

    expect(DB::table('credit_adjustments')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'billing.credit_adjusted')->count())->toBe(1);
    $this->actingAs($this->t['viewer'])->getJson(billingUrl($this->t['workspace'], 'credits/transactions'))->assertOk()->assertJsonCount(1, 'data');
    $this->artisan('sitex:billing:verify-ledger')->assertSuccessful();
});

it('never returns or logs payment provider secrets', function () {
    $super = User::factory()->platform(PlatformRole::BillingAdmin)->create();
    $secret = 'whsec_'.str_repeat('s3cr3t', 6);

    $this->actingAs($super)->putJson('/api/admin/v1/billing/providers/bankfeed', ['adapter' => 'signed_webhook', 'is_enabled' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'provider_config_incomplete');
    $res = $this->actingAs($super)->putJson('/api/admin/v1/billing/providers/bankfeed', [
        'adapter' => 'signed_webhook', 'name' => 'Bank feed', 'is_enabled' => true,
        'settings' => ['bank_bin' => '970436', 'account_no' => '0123456789', 'account_name' => 'SITEX'],
        'credentials' => ['webhook_secret' => $secret],
    ])->assertOk();

    $all = $res->getContent()
        .$this->actingAs($super)->getJson('/api/admin/v1/billing/providers')->getContent()
        .$this->actingAs($this->t['owner'])->getJson(billingUrl($this->t['workspace'], 'plans'))->getContent()
        .json_encode(DB::table('audit_logs')->get());
    expect($all)->not->toContain($secret)
        ->and(DB::table('payment_provider_configs')->value('credentials'))->not->toContain($secret);
});

it('reports revenue and gross profit only from real records', function () {
    $staff = User::factory()->platform(PlatformRole::BillingAdmin)->create();
    $this->actingAs($staff)->getJson('/api/admin/v1/billing/reports/revenue')->assertOk();
    $this->actingAs($staff)->getJson('/api/admin/v1/billing/reports/costs')->assertOk();
    $p = $this->actingAs($staff)->getJson('/api/admin/v1/billing/reports/profitability')->assertOk()->json('data');
    expect(json_encode($p))->not->toContain('NaN');
});
