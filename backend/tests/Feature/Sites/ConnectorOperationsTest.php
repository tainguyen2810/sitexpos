<?php

use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Actions\PublishPost;
use SiteXPost\Modules\Sites\Actions\RunHealthCheck;
use SiteXPost\Modules\Sites\Actions\SyncSite;
use SiteXPost\Modules\Sites\Actions\TestPublish;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Domain\SitePublication;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use Tests\Support\FakeWordPress;

beforeEach(fn () => fakePublicDns());

function inTenant(Workspace $ws, callable $fn): mixed
{
    return app(TenantContext::class)->runAs($ws, null, $fn);
}

it('health check: connected when the signed response verifies, degraded otherwise', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $check = inTenant($ws, fn () => app(RunHealthCheck::class)->handle($site));
    expect($check->ok)->toBeTrue()->and($site->fresh()->wp_version)->toBe('6.8.3');

    $wp->online = false;
    $check = inTenant($ws, fn () => app(RunHealthCheck::class)->handle($site->fresh()));
    expect($check->ok)->toBeFalse()->and($check->error_code)->toBe('unreachable')
        ->and($site->fresh()->status->value)->toBe('degraded');

    $wp->online = true;
    $wp->tamperResponses = true;
    $check = inTenant($ws, fn () => app(RunHealthCheck::class)->handle($site->fresh()));
    expect($check->error_code)->toBe('invalid_response_signature');

    $wp->tamperResponses = false;
    $wp->forceStatus = 404;
    $check = inTenant($ws, fn () => app(RunHealthCheck::class)->handle($site->fresh()));
    expect($check->error_code)->toBe('plugin_disabled_or_missing' === 'x' ? '' : 'request_rejected');

    $wp->forceStatus = null;
    inTenant($ws, fn () => app(RunHealthCheck::class)->handle($site->fresh()));
    expect($site->fresh()->status->value)->toBe('connected');

    // Logs are visible to the workspace.
    $logs = $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/logs?filter[level]=error")->assertOk()->json('data');
    expect(collect($logs)->pluck('error_code'))->toContain('unreachable')->toContain('invalid_response_signature');
    expect(json_encode($logs))->not->toContain('X-SiteX-Signature');
});

it('refuses to call a site whose DNS now points to a private address', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    app()->instance(SsrfGuard::class, new SsrfGuard([], false, fn () => ['10.0.0.8']));
    $check = inTenant($ws, fn () => app(RunHealthCheck::class)->handle($site));

    expect($check->error_code)->toBe('blocked_url')->and($wp->calls)->toBe([]);
});

it('syncs taxonomies, content and WooCommerce products as untrusted data', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $result = inTenant($ws, fn () => app(SyncSite::class)->handle($site));
    expect($result['taxonomies'])->toBe(4)->and($result['content'])->toBe(['post' => 1, 'page' => 1, 'product' => 1]);

    $terms = $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/taxonomies")->assertOk()->json('data');
    expect(collect($terms)->pluck('name'))->toContain('Hướng dẫn')->not->toContain('<b>Hướng dẫn</b>');

    $content = collect($this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/content")->json('data'));
    expect($content->firstWhere('wp_post_id', 11)['title'])->toBe('Bài mẫu x')
        ->and($content->firstWhere('wp_post_id', 12)['url'])->toBeNull() // foreign host dropped
        ->and($content->firstWhere('wp_post_id', 13)['product']['price'])->toBe('199000');

    $products = $this->actingAs($owner)->getJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/content?filter[post_type]=product")->json('data');
    expect($products)->toHaveCount(1);

    // v1.0.9: author (plugin ≥ 1.2.0) and category/author filters for "learn style from…".
    $base = "/api/v1/workspaces/{$ws->id}/sites/{$site->id}";
    expect($content->firstWhere('wp_post_id', 11)['author_name'])->toBe('Nguyễn Lan')
        ->and($content->firstWhere('wp_post_id', 11)['author_wp_id'])->toBe(3)
        ->and($content->firstWhere('wp_post_id', 11)['term_ids'])->toBe([1, 5])
        ->and($content->firstWhere('wp_post_id', 12)['author_wp_id'])->toBeNull();
    expect($this->actingAs($owner)->getJson("{$base}/content?filter[author]=3")->json('data'))->toHaveCount(1)
        ->and($this->actingAs($owner)->getJson("{$base}/content?filter[term]=5")->json('data'))->toHaveCount(1)
        ->and($this->actingAs($owner)->getJson("{$base}/content?filter[term]=99")->json('data'))->toHaveCount(0)
        ->and($this->actingAs($owner)->getJson("{$base}/content?filter[status]=publish&filter[post_type]=post")->json('data'))->toHaveCount(1);
    expect($this->actingAs($owner)->getJson("{$base}/authors")->assertOk()->json('data'))->toBe([['wp_author_id' => 3, 'name' => 'Nguyễn Lan', 'posts' => 1]]);

    // Items removed on WordPress disappear on the next full sync.
    $wp->woocommerce = false;
    inTenant($ws, function () use ($site) {
        $site->refresh()->forceFill(['capabilities' => ['features' => ['woocommerce' => false]]])->save();
        app(SyncSite::class)->handle($site);
    });
    expect(inTenant($ws, fn () => SiteContentItem::query()->where('post_type', 'product')->count()))->toBe(0)
        ->and(inTenant($ws, fn () => SiteTaxonomy::query()->where('taxonomy', 'product_cat')->count()))->toBe(0);
});

it('test publish creates one draft with images and SEO; retries update the same post', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/test-publish")->assertStatus(202)
        ->assertJsonPath('data.job_run.type', 'sites.test_publish');

    expect($wp->posts)->toHaveCount(1)->and($wp->media)->toHaveCount(2);
    $post = array_values($wp->posts)[0];
    expect($post['status'])->toBe('draft')
        ->and($post['featured'])->toBeInt()
        ->and($post['content'])->toContain('/wp-content/uploads/sitex-test-inline.png')
        ->and($post['content'])->not->toContain('{{sitex-image')
        ->and($post['seo']['noindex'])->toBeTrue()
        ->and($post['seo']['focus_keyword'])->toBe('sitex post');

    // Retry three times: still ONE post and the same two images.
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/test-publish")->assertStatus(202);
    }
    expect($wp->posts)->toHaveCount(1)->and($wp->media)->toHaveCount(2)
        ->and(array_values($wp->posts)[0]['writes'])->toBe(4);

    $publication = inTenant($ws, fn () => SitePublication::query()->where('idempotency_key', TestPublish::IDEMPOTENCY_KEY)->sole());
    expect($publication->attempts)->toBe(4)->and($publication->status)->toBe('succeeded');
});

it('never duplicates a post even when Cloud lost the first response', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $doc = ['idempotency_key' => 'article-01-v1', 'title' => 'Bài A', 'content_html' => '<p>A</p>', 'status' => 'draft'];

    $first = inTenant($ws, fn () => app(PublishPost::class)->handle($site, $doc));

    // Simulate a timeout after WordPress created the post: Cloud has no mapping.
    inTenant($ws, fn () => $first->forceFill(['wp_post_id' => null, 'status' => 'failed'])->save());

    $retry = inTenant($ws, fn () => app(PublishPost::class)->handle($site, array_merge($doc, ['title' => 'Bài A (sửa)'])));
    expect($retry->wp_post_id)->toBe($first->getOriginal('wp_post_id') ?? array_key_first($wp->posts))
        ->and($wp->posts)->toHaveCount(1)
        ->and(array_values($wp->posts)[0]['title'])->toBe('Bài A (sửa)');
});

it('marks the publication failed with a readable code when WordPress refuses', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $wp->forceStatus = 500;

    expect(fn () => inTenant($ws, fn () => app(PublishPost::class)->handle($site, ['idempotency_key' => 'k-000001', 'title' => 'T', 'content_html' => ''])))
        ->toThrow(ConnectorException::class);

    $pub = inTenant($ws, fn () => SitePublication::query()->where('idempotency_key', 'k-000001')->sole());
    expect($pub->status)->toBe('failed')->and($pub->last_error_code)->toBe('site_error');
});

it('revoking from Cloud notifies the plugin and removes all write access', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/revoke")->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('meta.plugin_notified', true);
    expect(collect($wp->calls)->last()['route'])->toBe('/sitex-post/v1/disconnect');

    $calls = count($wp->calls);
    expect(fn () => inTenant($ws, fn () => app(PublishPost::class)->handle($site->fresh(), ['idempotency_key' => 'after-revoke', 'title' => 'T', 'content_html' => ''])))
        ->toThrow(ConnectorException::class, __('sites.errors.not_connected'));
    expect(count($wp->calls))->toBe($calls);

    foreach (['health-check', 'sync', 'test-publish', 'rotate-keys'] as $action) {
        $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/{$action}")->assertStatus(409);
    }
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/revoke")->assertStatus(409);
});

it('revokes even when the plugin cannot be reached', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $wp->online = false;

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/revoke")->assertOk()
        ->assertJsonPath('meta.plugin_notified', false)->assertJsonPath('data.status', 'revoked');
});

it('rotation fails safely (old key kept) when the plugin does not confirm', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $before = siteCredential($site)->key_id;
    $wp->online = false;

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/rotate-keys")->assertStatus(502)
        ->assertJsonPath('error.code', 'rotation_failed');
    expect(siteCredential($site)->key_id)->toBe($before);
});

it('follows a rotation WordPress applied even when its answer was lost', function () {
    ['workspace' => $ws, 'owner' => $owner] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);
    $before = siteCredential($site)->key_id;
    $wp->tamperRoute = '/sitex-post/v1/credentials/rotate';

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/rotate-keys")->assertOk();
    $after = siteCredential($site)->key_id;
    expect($after)->not->toBe($before)->and($wp->keys)->toHaveKey($after);

    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/sites/{$site->id}/health-check")->assertStatus(202);
    expect($site->fresh()->status->value)->toBe('connected');
});

it('scheduled health checks queue only connected sites past the interval', function () {
    ['workspace' => $ws] = workspaceWithRoles();
    $wp = (new FakeWordPress)->install();
    $site = connectedSite($ws, $wp);

    $this->artisan('sitex:sites:health')->expectsOutputToContain('Queued 1 health checks')->assertSuccessful();
    expect($site->fresh()->last_health_at)->not->toBeNull();
    $this->artisan('sitex:sites:health')->expectsOutputToContain('Queued 0 health checks')->assertSuccessful();
    $this->artisan('sitex:sites:prune')->assertSuccessful();
});
