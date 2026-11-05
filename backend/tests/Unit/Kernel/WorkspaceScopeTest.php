<?php

use SiteXPost\Kernel\Tenancy\CrossTenantWriteException;
use SiteXPost\Kernel\Tenancy\MissingTenantContextException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

it('throws when a tenant model is queried without tenant context (fail-closed)', function () {
    createWorkspace();

    WorkspaceMember::query()->get();
})->throws(MissingTenantContextException::class);

it('scopes queries to the current tenant only', function () {
    $a = createWorkspace(name: 'A');
    $b = createWorkspace(name: 'B');
    $ctx = app(TenantContext::class);

    $idsInA = $ctx->runAs($a, null, fn () => WorkspaceMember::query()->pluck('workspace_id')->unique()->all());
    $idsInB = $ctx->runAs($b, null, fn () => WorkspaceMember::query()->pluck('workspace_id')->unique()->all());

    expect($idsInA)->toBe([$a->id])->and($idsInB)->toBe([$b->id]);
});

it('allows reviewed cross-tenant reads only inside bypass()', function () {
    createWorkspace(name: 'A');
    createWorkspace(name: 'B');

    expect(app(TenantContext::class)->bypass(fn () => WorkspaceMember::query()->count()))->toBe(2);
});

it('assigns workspace_id from the context on create', function () {
    $a = createWorkspace(name: 'A');
    $member = addMember($a, WorkspaceRole::Viewer);

    expect($member->workspace_id)->toBe($a->id);
});

it('refuses to create a tenant model for another workspace than the context', function () {
    $a = createWorkspace(name: 'A');
    $b = createWorkspace(name: 'B');

    app(TenantContext::class)->runAs($a, null, function () use ($b) {
        $inv = new WorkspaceInvitation;
        $inv->forceFill([
            'workspace_id' => $b->id,
            'email' => 'x@example.com',
            'role' => 'VIEWER',
            'token_hash' => str_repeat('a', 64),
            'invited_by' => $b->owner_id,
            'expires_at' => now()->addDay(),
        ])->save();
    });
})->throws(CrossTenantWriteException::class);

it('refuses to create a tenant model without context', function () {
    $a = createWorkspace(name: 'A');
    $inv = new WorkspaceInvitation;
    $inv->forceFill([
        'workspace_id' => $a->id,
        'email' => 'x@example.com',
        'role' => 'VIEWER',
        'token_hash' => str_repeat('b', 64),
        'invited_by' => $a->owner_id,
        'expires_at' => now()->addDay(),
    ])->save();
})->throws(MissingTenantContextException::class);

it('refuses to move a tenant model to another workspace', function () {
    $a = createWorkspace(name: 'A');
    $b = createWorkspace(name: 'B');

    app(TenantContext::class)->runAs($a, null, function () use ($b) {
        $member = WorkspaceMember::query()->firstOrFail();
        $member->forceFill(['workspace_id' => $b->id])->save();
    });
})->throws(CrossTenantWriteException::class);

it('restores the previous context after runAs()', function () {
    $a = createWorkspace(name: 'A');
    $b = createWorkspace(name: 'B');
    $ctx = app(TenantContext::class);

    $ctx->runAs($a, null, function () use ($ctx, $a, $b) {
        $ctx->runAs($b, null, fn () => null);
        expect($ctx->workspaceId())->toBe($a->id);
    });

    expect($ctx->has())->toBeFalse();
});
