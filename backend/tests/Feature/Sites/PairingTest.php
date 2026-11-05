<?php

use Illuminate\Support\Facades\Queue;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Domain\SitePairing;
use SiteXPost\Modules\Sites\Jobs\HealthCheckSite;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use Tests\Support\FakeWordPress;

beforeEach(fn () => fakePublicDns());

/**
 * @return array{ws: Workspace, owner: User, site_id: string, code: string}
 */
function siteAwaitingPairing(string $url = 'https://blog.example.com'): array
{
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles('Cửa hàng A');
    $res = test()->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites", ['name' => 'Blog', 'url' => $url])->assertCreated();

    return ['ws' => $ws, 'owner' => $owner, 'site_id' => $res->json('data.id'), 'code' => $res->json('meta.pairing_code')];
}

function pairPayload(string $code, string $siteUrl = 'https://blog.example.com/'): array
{
    return [
        'pairing_code' => $code,
        'site_url' => $siteUrl,
        'rest_url' => rtrim($siteUrl, '/').'/wp-json/',
        'publish_author' => ['wp_user_id' => 2, 'display_name' => 'SiteX Author'],
        'confirmed_by' => ['wp_user_id' => 1, 'display_name' => 'Admin'],
        'environment' => ['wp_version' => '6.8.3', 'php_version' => '8.2.12', 'plugin_version' => '1.0.0', 'locale' => 'vi'],
    ];
}

it('shows the WordPress admin who is asking before anything is granted', function () {
    ['code' => $code, 'site_id' => $siteId] = siteAwaitingPairing();

    $this->postJson('/connector/v1/pair/preview', ['pairing_code' => strtolower($code), 'site_url' => 'https://blog.example.com/'])
        ->assertOk()
        ->assertJsonPath('data.workspace_name', 'Cửa hàng A')
        ->assertJsonPath('data.site_name', 'Blog')
        ->assertJsonPath('data.permissions', fn ($p) => in_array('create_posts', $p, true));

    $pairing = app(TenantContext::class)->bypass(fn () => SitePairing::query()->where('site_id', $siteId)->firstOrFail());
    expect($pairing->used_at)->toBeNull();
});

it('pairs after confirmation, issues a per-site secret once and verifies the reverse direction', function () {
    Queue::fake();
    ['code' => $code, 'site_id' => $siteId, 'ws' => $ws, 'owner' => $owner] = siteAwaitingPairing();

    $res = $this->postJson('/connector/v1/pair', pairPayload($code))->assertOk();
    $secret = $res->json('data.secret');
    $keyId = $res->json('data.key_id');
    expect(strlen($secret))->toBeGreaterThanOrEqual(43)
        ->and($res->json('data.signature_version'))->toBe('SITEX-HMAC-V1')
        ->and($res->json('data.workspace_name'))->toBe('Cửa hàng A');

    // Secret is encrypted at rest.
    $credential = app(TenantContext::class)->bypass(fn () => SiteCredential::query()->where('key_id', $keyId)->firstOrFail());
    expect($credential->getRawOriginal('secret_encrypted'))->not->toContain($secret)
        ->and($credential->secret)->toBe($secret);

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$siteId}")->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.versions.wordpress', '6.8.3')
        ->assertJsonPath('data.publish_author.wp_user_id', 2);

    Queue::assertPushed(HealthCheckSite::class, fn ($job) => $job->siteId === $siteId);
    expect(AuditLog::query()->where('action', 'site.connected')->where('subject_id', $siteId)->exists())->toBeTrue();

    // The code is single use.
    $this->postJson('/connector/v1/pair', pairPayload($code))->assertStatus(422)->assertJsonPath('error.code', 'invalid_pairing_code');
});

it('marks the site connected after the signed health check succeeds end-to-end', function () {
    $wp = new FakeWordPress;
    $wp->install();
    ['code' => $code, 'site_id' => $siteId, 'ws' => $ws, 'owner' => $owner] = siteAwaitingPairing();

    // The first (immediate, sync queue) callback runs before the plugin stored the key → degraded; then it is stored.
    $res = $this->postJson('/connector/v1/pair', pairPayload($code));
    $wp->keys[$res->json('data.key_id')] = $res->json('data.secret');

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$siteId}/health-check")->assertStatus(202);

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$siteId}")
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.features.yoast', true)
        ->assertJsonPath('data.last_error', null);
});

it('rejects a site URL that differs from the declared one', function () {
    ['code' => $code] = siteAwaitingPairing();

    $this->postJson('/connector/v1/pair', pairPayload($code, 'https://other.example.com/'))
        ->assertStatus(409)->assertJsonPath('error.code', 'site_url_mismatch'); // only someone holding a valid code learns this

    ['code' => $code2] = siteAwaitingPairing('https://shop.example.com');
    $this->postJson('/connector/v1/pair', pairPayload($code2, 'https://blog.example.com/'))
        ->assertStatus(409)->assertJsonPath('error.code', 'site_url_mismatch');
});

it('rejects a REST URL on another host', function () {
    ['code' => $code] = siteAwaitingPairing();
    $payload = pairPayload($code);
    $payload['rest_url'] = 'https://attacker.example.net/wp-json/';

    $this->postJson('/connector/v1/pair', $payload)->assertStatus(422);
});

it('kills a code after 5 wrong guesses for the same site', function () {
    ['code' => $code, 'site_id' => $siteId] = siteAwaitingPairing();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/connector/v1/pair/preview', ['pairing_code' => 'AAAA-AAAA', 'site_url' => 'https://blog.example.com'])->assertStatus(422);
    }

    $this->postJson('/connector/v1/pair', pairPayload($code))->assertStatus(422)->assertJsonPath('error.code', 'invalid_pairing_code');
    expect(app(TenantContext::class)->bypass(fn () => Site::query()->find($siteId))->status->value)->toBe('pending_pairing');
});

it('rejects expired codes and codes superseded by a new one', function () {
    ['code' => $code, 'site_id' => $siteId, 'ws' => $ws, 'owner' => $owner] = siteAwaitingPairing();

    $new = $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$siteId}/pairing-code")->assertCreated()->json('data.pairing_code');
    $this->postJson('/connector/v1/pair', pairPayload($code))->assertStatus(422);

    $this->travel(16)->minutes();
    $this->postJson('/connector/v1/pair', pairPayload($new))->assertStatus(422);
});

it('rate limits pairing attempts per IP', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/connector/v1/pair/preview', ['pairing_code' => 'BBBB-BBBB', 'site_url' => 'https://nothing.example.com']);
    }
    $this->postJson('/connector/v1/pair/preview', ['pairing_code' => 'BBBB-BBBB', 'site_url' => 'https://nothing.example.com'])
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('does not issue a new code while connected, but allows re-pairing after revoke', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/pairing-code")->assertStatus(409);
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/revoke")->assertOk();
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/pairing-code")->assertCreated();
    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}")->assertJsonPath('data.status', 'pending_pairing');
});
