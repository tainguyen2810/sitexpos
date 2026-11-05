<?php

use Illuminate\Testing\TestResponse;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Connector\Signer;
use SiteXPost\Modules\Sites\Domain\Site;
use Tests\Support\FakeWordPress;

beforeEach(fn () => fakePublicDns());

/**
 * @return array{ws: mixed, owner: mixed, site: Site, wp: FakeWordPress, key: string, secret: string}
 */
function pairedFixture(): array
{
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $cred = siteCredential($site);

    return ['ws' => $ws, 'owner' => $owner, 'site' => $site, 'wp' => $wp, 'key' => $cred->key_id, 'secret' => $cred->secret];
}

function heartbeat(string $key, string $secret, array $body = ['plugin_version' => '1.0.1'], ?int $ts = null, ?string $nonce = null): TestResponse
{
    return test()->call('POST', '/connector/v1/heartbeat', [], [], [], test()->transformHeadersToServerVars(
        pluginHeaders($key, $secret, '/connector/v1/heartbeat', $body, $ts, $nonce) + ['Accept' => 'application/json'],
    ), (string) json_encode($body));
}

it('accepts a correctly signed heartbeat and records it', function () {
    ['site' => $site, 'key' => $key, 'secret' => $secret] = pairedFixture();

    heartbeat($key, $secret)->assertOk()->assertJsonPath('data.site_status', 'connected');

    $fresh = app(TenantContext::class)->bypass(fn () => Site::query()->find($site->id));
    expect($fresh->plugin_version)->toBe('1.0.1')->and($fresh->last_seen_at)->not->toBeNull();
});

it('rejects bad signatures, wrong keys, skewed clocks and replayed nonces', function () {
    ['key' => $key, 'secret' => $secret] = pairedFixture();

    heartbeat($key, 'd3Jvbmctc2VjcmV0LXdyb25nLXNlY3JldC13cm9uZyEh')->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
    heartbeat('01JUNKNOWNKEY0000000000000', $secret)->assertStatus(401);
    heartbeat($key, $secret, ts: time() - 301)->assertStatus(401);
    heartbeat($key, $secret, ts: time() + 301)->assertStatus(401);

    $nonce = Signer::nonce();
    heartbeat($key, $secret, nonce: $nonce)->assertOk();
    heartbeat($key, $secret, nonce: $nonce)->assertStatus(401); // replay

    // Body tampering: signature over one body, another body sent.
    $headers = pluginHeaders($key, $secret, '/connector/v1/heartbeat', ['plugin_version' => '1.0.0']);
    $this->call('POST', '/connector/v1/heartbeat', [], [], [], $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']), '{"plugin_version":"9.9.9"}')
        ->assertStatus(401);

    // Missing headers.
    $this->postJson('/connector/v1/heartbeat', [])->assertStatus(401);
});

it('revokes the site when the WordPress admin disconnects, and the key stops working', function () {
    ['site' => $site, 'key' => $key, 'secret' => $secret, 'ws' => $ws, 'owner' => $owner, 'wp' => $wp] = pairedFixture();

    $body = ['reason' => 'disconnected_from_wordpress'];
    $this->call('POST', '/connector/v1/revoke', [], [], [], $this->transformHeadersToServerVars(
        pluginHeaders($key, $secret, '/connector/v1/revoke', $body) + ['Accept' => 'application/json'],
    ), (string) json_encode($body))->assertOk();

    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}")
        ->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.revoked_by', 'wordpress');

    heartbeat($key, $secret)->assertStatus(401);

    // Cloud can no longer write either (no request even leaves Cloud).
    $calls = count($wp->calls);
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/test-publish")->assertStatus(409);
    expect(count($wp->calls))->toBe($calls);
});

it('keeps the previous key valid only during the rotation grace window', function () {
    ['site' => $site, 'key' => $oldKey, 'secret' => $oldSecret, 'ws' => $ws, 'owner' => $owner] = pairedFixture();

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/rotate-keys")->assertOk();
    $new = siteCredential($site);
    expect($new->key_id)->not->toBe($oldKey);

    heartbeat($new->key_id, $new->secret)->assertOk();
    heartbeat($oldKey, $oldSecret)->assertOk(); // still in grace

    $this->travel(11)->minutes();
    heartbeat($oldKey, $oldSecret)->assertStatus(401);
    heartbeat($new->key_id, $new->secret)->assertOk();
});

it('refuses plugin calls for a suspended workspace', function () {
    ['ws' => $ws, 'key' => $key, 'secret' => $secret] = pairedFixture();
    $ws->forceFill(['status' => 'suspended'])->save();

    heartbeat($key, $secret)->assertStatus(401);
});
