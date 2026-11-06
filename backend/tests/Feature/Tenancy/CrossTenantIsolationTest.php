<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Accounting\Documents;
use SiteXPost\Modules\Billing\Payments\PaymentOrders;
use SiteXPost\Modules\Billing\Support\InTenant;
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
    $researchB = researchFixture($b['workspace'], $siteB);
    $writerB = writerFixture($b['workspace'], $siteB);
    $phase6B = phase6Fixture($b['workspace'], $siteB, $writerB);
    $phase8B = phase8Fixture($b['workspace'], $siteB, $writerB);

    // Phase 7: a payment order + internal receipt owned by tenant B.
    billingPlan('ct-pro', 100_000);
    billingProvider();
    $orderB = app(PaymentOrders::class)->forSubscription($b['workspace']->id, 'ct-pro', 'month', null, null, $b['owner']->id);
    $docB = InTenant::run($b['workspace']->id, fn () => app(Documents::class)->receipt($orderB));

    return [
        'a' => $a,
        'b' => $b,
        'ids' => [
            'member' => memberOf($b['workspace'], $b['editor'])->id,
            'invitation' => $invB->id,
            'jobRun' => $jobB->id,
            'site' => $siteB->id,
            'workflowRun' => $runB->id,
            'paymentOrder' => $orderB->id,
            'billingDocument' => $docB->id,
        ] + $researchB + $writerB + $phase6B + $phase8B,
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

// The enumeration sends more requests than the per-user API limit allows
// in one minute; throttling is covered by its own tests.
beforeEach(fn () => $this->withoutMiddleware(ThrottleRequests::class));

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
        if (! preg_match('/\{(member|invitation|jobRun|site|workflowRun|campaign|notification|article|preset|batch|articleRef|asset|imageJob|auditRun|suggestion|publishingBatch|mapping|publishingJob|conflict|paymentOrder|billingDocument|opportunity|decay|recommendation|experiment|refreshJob|report|copilotQuery|agentRun|agentTask|automationSchedule)\}/', $uri)) {
            continue;
        }
        $url = fillUri($uri, $a['workspace']->id, $ids);
        $res = $this->actingAs($a['owner'])->json($method, $url, ['role' => 'VIEWER']);

        expect($res->status())->toBe(404, "{$method} {$url} returned {$res->status()}");
    }

    expect(memberOf($b['workspace'], $b['editor'])->role->value)->toBe('EDITOR')
        ->and(app(TenantContext::class)->runAs($b['workspace'], null, fn () => WorkflowRun::query()->sole()->status->value))->toBe('pending');
});

it('never lets tenant A reach tenant B research rows through its own campaign', function () {
    ['a' => $a, 'b' => $b, 'ids' => $ids] = twoTenants();
    $siteA = connectedSite($a['workspace'], new FakeWordPress('https://a.example.com'), 'Site A');
    $own = researchFixture($a['workspace'], $siteA, 'A');

    $checked = 0;
    foreach (tenantRoutes() as [$method, $uri]) {
        if (! str_contains($uri, '{campaign}') || ! preg_match('/\{(knowledge|researchRun|keyword|idea|cluster|gap|competitor|calendarItem)\}/', $uri)) {
            continue;
        }
        $url = fillUri($uri, $a['workspace']->id, ['campaign' => $own['campaign']] + $ids);
        $res = $this->actingAs($a['owner'])->json($method, $url, ['status' => 'rejected', 'value' => 'hacked', 'name' => 'hacked']);
        expect($res->status())->toBe(404, "{$method} {$url} returned {$res->status()}");
        $checked++;
    }
    expect($checked)->toBeGreaterThanOrEqual(15);

    $body = $this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/research/campaigns")->getContent()
        .$this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/research/notifications")->getContent()
        .$this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/research/calendar")->getContent()
        .$this->actingAs($a['owner'])->getJson("/api/v1/workspaces/{$a['workspace']->id}/research/campaigns/{$own['campaign']}/keywords")->getContent();
    expect($body)->not->toContain('Secret idea B')->not->toContain('secret keyword B')->not->toContain('Campaign B')->not->toContain('Secret note B');
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
