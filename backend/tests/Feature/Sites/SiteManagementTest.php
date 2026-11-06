<?php

use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\SitePairing;
use Tests\Support\FakeWordPress;

beforeEach(fn () => fakePublicDns());

it('lets OWNER and ADMIN add a website and returns the pairing code exactly once', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin] = workspaceWithRoles();

    $res = $this->actingAs($admin)->postJson("/api/v1/workspaces/{$ws->id}/sites", [
        'name' => 'Blog Hoa Mai', 'url' => 'https://Blog.Example.com/', 'content_language' => 'vi',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending_pairing')
        ->assertJsonPath('data.url', 'https://blog.example.com');

    $code = $res->json('meta.pairing_code');
    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and($res->json('meta.pairing_expires_at'))->not->toBeNull();

    $siteId = $res->json('data.id');
    $show = $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$siteId}")->assertOk();
    expect(json_encode($show->json()))->not->toContain($code)->not->toContain('secret')->not->toContain('code_hash');

    $stored = app(TenantContext::class)->bypass(fn () => SitePairing::query()->where('site_id', $siteId)->firstOrFail());
    expect($stored->code_hash)->not->toBe($code)->and($stored->code_hash)->toBe(SitePairing::hashCode($code));

    expect(AuditLog::query()->where('action', 'site.created')->where('subject_id', $siteId)->exists())->toBeTrue();
});

it('forbids EDITOR and VIEWER from adding websites', function () {
    ['workspace' => $ws, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();

    foreach ([$editor, $viewer] as $user) {
        $this->actingAs($user)->postJson("/api/v1/workspaces/{$ws->id}/sites", ['name' => 'X', 'url' => 'https://x.example.com'])
            ->assertForbidden();
    }
});

it('requires a verified email to add a website', function () {
    $owner = User::factory()->create();
    $ws = createWorkspace($owner);
    $owner->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites", ['name' => 'X', 'url' => 'https://x.example.com'])
        ->assertForbidden()->assertJsonPath('error.code', 'email_not_verified');
});

it('rejects duplicates, plain http and private addresses (SSRF)', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $url = "/api/v1/workspaces/{$ws->id}/sites";

    $this->actingAs($owner)->postJson($url, ['name' => 'A', 'url' => 'https://a.example.com'])->assertCreated();
    $this->actingAs($owner)->postJson($url, ['name' => 'A2', 'url' => 'https://A.example.com/'])->assertStatus(409)->assertJsonPath('error.code', 'site_exists');
    $this->actingAs($owner)->postJson($url, ['name' => 'B', 'url' => 'http://b.example.com'])->assertStatus(422)->assertJsonValidationErrors('url', 'error.details');

    foreach (['https://127.0.0.1', 'https://10.1.2.3', 'https://169.254.169.254', 'https://[::1]'] as $private) {
        $this->actingAs($owner)->postJson($url, ['name' => 'P', 'url' => $private])->assertStatus(422);
    }

    app()->instance(SsrfGuard::class, new SsrfGuard([], false, fn () => ['192.168.1.10']));
    $this->actingAs($owner)->postJson($url, ['name' => 'Rebind', 'url' => 'https://internal.example.com'])
        ->assertStatus(422)->assertJsonPath('error.details.url.0', fn ($m) => str_contains($m, 'private_address'));
});

it('lists, updates and deletes websites with role checks', function () {
    ['workspace' => $ws, 'owner' => $owner, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $this->actingAs($viewer)->getJson("/api/v1/workspaces/{$ws->id}/sites")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'connected');

    $this->actingAs($editor)->patchJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}", [
        'name' => 'Blog mới', 'settings' => ['default_post_status' => 'pending'],
    ])->assertOk()->assertJsonPath('data.name', 'Blog mới')->assertJsonPath('data.settings.default_post_status', 'pending');

    $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}", ['settings' => ['default_post_status' => 'trash']])
        ->assertStatus(422);

    $this->actingAs($viewer)->deleteJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}")->assertForbidden();
    $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}")->assertNoContent();

    // Deleting a connected site revokes it first (plugin told to wipe its key).
    expect(collect($wp->calls)->pluck('route'))->toContain('/sitex-post/v1/disconnect');
    $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}")->assertNotFound();
});

it('applies the role matrix to connector actions', function () {
    ['workspace' => $ws, 'owner' => $owner, 'editor' => $editor, 'viewer' => $viewer] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $base = "/api/v1/workspaces/{$ws->id}/sites/{$site->id}";

    // EDITOR: read-only operations on WordPress are allowed.
    $this->actingAs($editor)->postJson("{$base}/health-check")->assertStatus(202);
    $this->actingAs($editor)->postJson("{$base}/sync")->assertStatus(202);
    foreach (['test-publish', 'rotate-keys', 'revoke', 'pairing-code'] as $action) {
        $this->actingAs($editor)->postJson("{$base}/{$action}")->assertForbidden();
    }

    // VIEWER: only reads.
    foreach (['health-check', 'sync', 'test-publish', 'rotate-keys', 'revoke'] as $action) {
        $this->actingAs($viewer)->postJson("{$base}/{$action}")->assertForbidden();
    }
    foreach (['', '/taxonomies', '/content', '/logs', '/health-checks'] as $read) {
        $this->actingAs($viewer)->getJson($base.$read)->assertOk();
    }

    $this->actingAs($owner)->postJson("{$base}/test-publish")->assertStatus(202);
});
