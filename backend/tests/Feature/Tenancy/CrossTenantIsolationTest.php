<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use Tests\Support\FakeWordPress;

/**
 * Tenant A must never read or modify tenant B. This test enumerates EVERY
 * route under /api/v1/workspaces/{workspace} so new routes are covered
 * automatically.
 */
function tenantRoutes(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/workspaces/{workspace}'))
        ->flatMap(fn (Route $r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => [$m, $r->uri()]))
        ->values()
        ->all();
}

/**
 * @return array{a: array, b: array, ids: array<string, string>}
 */
function twoTenants(): array
{
    Notification::fake();
    seedAi();
    $a = workspaceWithRoles('Tenant A');
    $b = workspaceWithRoles('Tenant B');
    $ctx = app(TenantContext::class);

    $invB = $ctx->runAs($b['workspace'], null, function () use ($b) {
        $inv = new WorkspaceInvitation;
        $inv->forceFill([
            'email' => 'pending-b@example.com', 'role' => WorkspaceRole::Viewer,
            'token_hash' => hash('sha256', 'b-token'), 'invited_by' => $b['owner']->id,
            'expires_at' => now()->addDay(),
        ])->save();
        Audit::record('test.secret_b_event', $inv);

        return $inv;
    });

    $jobB = $ctx->runAs($b['workspace'], null, function () {
        $job = new JobRun;
        $job->forceFill(['type' => 'test'])->save();

        return $job;
    });

    $runB = $ctx->runAs($b['workspace'], null, function () {
        Queue::fake();

        return app(WorkflowEngine::class)
            ->start('ai.test', ['task_key' => 'system.test', 'prompt' => 'secret B prompt', 'structured' => false, 'web_search' => false])[0];
    });

    $wpB = (new FakeWordPress)->install();
    $siteB = connectedSite($b['workspace'], $wpB, 'Site B');

    return [
        'a' => $a,
        'b' => $b,
        'ids' => [
            'member' => memberOf($b['workspace'], $b['editor'])->id,
            'invitation' => $invB->id,
            'jobRun' => $jobB->id,
            'site' => $siteB->id,
            'workflowRun' => $runB->id,
        ],
        'siteB' => $siteB,
        'wpB' => $wpB,
    ];
}

function fillUri(string $uri, string $workspaceId, array $ids): string
{
    $uri = str_replace('{workspace}', $workspaceId, $uri);
    foreach ($ids as $key => $id) {
        $uri = str_replace('{'.$key.'}', $id, $uri);
    }

    return '/'.$uri;
}

it('covers at least the known tenant routes', function () {
    expect(count(tenantRoutes()))->toBeGreaterThanOrEqual(19);
});

it('returns 404 when a tenant A owner targets tenant B workspace ids', function () {
    ['a' => $a, 'b' => $b, 'ids' => $ids] = twoTenants();

    foreach (tenantRoutes() as [$method, $uri]) {
        $url = fillUri($uri, $b['workspace']->id, $ids);
        $res = $this->actingAs($a['owner'])->json($method, $url, [
            'name' => 'hacked', 'role' => 'VIEWER', 'email' => 'x@example.com',
            'confirm_name' => 'Tenant B', 'password' => 'Password12345', 'member_id' => $ids['member'],
        ]);

        expect($res->status())->toBe(404, "{$method} {$url} returned {$res->status()}");
    }

    expect($b['workspace']->fresh()->name)->toBe('Tenant B');
});

it('returns 404 when tenant A uses its own workspace with tenant B child ids', function () {
    ['a' => $a, 'b' => $b, 'ids' => $ids] = twoTenants();

    foreach (tenantRoutes() as [$method, $uri]) {
        if (! preg_match('/\{(member|invitation|jobRun|site|workflowRun)\}/', $uri)) {
            continue;
        }
        $url = fillUri($uri, $a['workspace']->id, $ids);
        $res = $this->actingAs($a['owner'])->json($method, $url, ['role' => 'VIEWER']);

        expect($res->status())->toBe(404, "{$method} {$url} returned {$res->status()}");
    }

    expect(memberOf($b['workspace'], $b['editor'])->role->value)->toBe('EDITOR')
        ->and(app(TenantContext::class)->runAs($b['workspace'], null, fn () => WorkflowRun::query()->sole()->status->value))->toBe('pending');
});

it('never lets tenant A operate tenant B WordPress sites', function () {
    ['a' => $a, 'b' => $b, 'ids' => $ids, 'wpB' => $wpB] = twoTenants();

    foreach (tenantRoutes() as [$method, $uri]) {
        if (! str_contains($uri, '{site}')) {
            continue;
        }
        foreach ([$a['workspace']->id, $b['workspace']->id] as $wsId) {
            $res = $this->actingAs($a['owner'])->json($method, fillUri($uri, $wsId, $ids), ['name' => 'hacked']);
            expect($res->status())->toBe(404, "{$method} {$uri} returned {$res->status()}");
        }
    }

    $sites = collect($this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/sites")->json('data'));
    expect($sites)->toBeEmpty()
        ->and($wpB->posts)->toBeEmpty()
        ->and(app(TenantContext::class)->runAs($b['workspace'], null, fn () => Site::query()->sole()->status->value))->toBe('connected');
});

it('cannot transfer ownership to a member of another tenant', function () {
    ['a' => $a, 'ids' => $ids] = twoTenants();

    $this->actingAs($a['owner'])->postJson("/api/v1/workspaces/{$a['workspace']->id}/transfer-ownership", [
        'member_id' => $ids['member'], 'password' => 'Password12345',
    ])->assertNotFound();
});

it('never leaks another tenant in listings or audit logs', function () {
    ['a' => $a, 'b' => $b] = twoTenants();

    $members = collect($this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/members")->json('data'));
    expect($members->pluck('user.id'))->not->toContain($b['owner']->id);

    $actions = collect($this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/audit-logs")->json('data'))->pluck('action');
    expect($actions)->not->toContain('test.secret_b_event')->and($actions)->toContain('workspace.created');

    $invitations = $this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/invitations")->json('data');
    expect(collect($invitations)->pluck('email'))->not->toContain('pending-b@example.com');

    $tasks = $this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/ai/tasks")->json('data');
    expect($tasks)->toBeEmpty();
    expect($this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/ai/usage")->json('data.totals.requests'))->toBe(0);

    $workspaces = collect($this->actingAs($a['owner'])->getJson('/api/v1/workspaces')->json('data'))->pluck('id');
    expect($workspaces->all())->toBe([$a['workspace']->id]);
});

it('rejects tenant identifiers supplied in the body', function () {
    ['a' => $a, 'b' => $b] = twoTenants();

    $this->actingAs($a['owner'])->patchJson("/api/v1/workspaces/{$a['workspace']->id}", [
        'name' => 'ok', 'workspace_id' => $b['workspace']->id,
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    $this->actingAs($a['owner'])->postJson("/api/v1/workspaces/{$a['workspace']->id}/invitations", [
        'email' => 'z@example.com', 'role' => 'VIEWER', 'tenant_id' => $b['workspace']->id,
    ])->assertStatus(422);
});

it('rejects malformed workspace ids without hitting the database', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/workspaces/1')->assertNotFound();
    $this->actingAs($user)->getJson("/api/v1/workspaces/1' OR '1'='1")->assertNotFound();
});

it('requires authentication for tenant routes', function () {
    $ws = createWorkspace();

    $this->getJson("/api/v1/workspaces/{$ws->id}")->assertUnauthorized();
});
