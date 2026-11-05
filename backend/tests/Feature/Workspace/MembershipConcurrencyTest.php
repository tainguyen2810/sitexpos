<?php

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Actions\ChangeMemberRole;
use SiteXPost\Modules\Workspace\Actions\RemoveMember;
use SiteXPost\Modules\Workspace\Actions\TransferOwnership;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

// PHASE1-AUDIT C11/C12: actions decide on the row re-read under lock, never on a
// model loaded earlier in the request. Interleavings are simulated by mutating
// the row between "request loads the model" and "action runs".

function ownerIds(Workspace $ws): array
{
    return app(TenantContext::class)->bypass(fn () => WorkspaceMember::query()
        ->where('workspace_id', $ws->id)->where('role', WorkspaceRole::Owner)->pluck('user_id')->all());
}

function inTenantAsOwner(Workspace $ws, callable $fn): mixed
{
    return app(TenantContext::class)->runAs($ws, WorkspaceRole::Owner, $fn);
}

function apiStatus(callable $fn): int
{
    try {
        $fn();
    } catch (ApiException $e) {
        return $e->getStatusCode();
    }

    return 200;
}

it('refuses to transfer ownership to a member removed concurrently and keeps exactly one OWNER', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin] = workspaceWithRoles();
    $stale = memberOf($ws, $admin);
    inTenantAsOwner($ws, fn () => WorkspaceMember::query()->whereKey($stale->id)->delete());

    $status = apiStatus(fn () => inTenantAsOwner($ws, fn () => app(TransferOwnership::class)->handle($ws, $stale, $owner)));

    expect($status)->toBe(404)
        ->and(ownerIds($ws))->toBe([$owner->id])
        ->and($ws->fresh()->owner_id)->toBe($owner->id);
});

it('refuses a transfer when the caller is no longer the OWNER', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin, 'editor' => $editor] = workspaceWithRoles();
    inTenantAsOwner($ws, fn () => app(TransferOwnership::class)->handle($ws, memberOf($ws, $admin), $owner));

    // A second, older request from the former owner arrives afterwards.
    $status = apiStatus(fn () => inTenantAsOwner($ws, fn () => app(TransferOwnership::class)->handle($ws, memberOf($ws, $editor), $owner)));

    expect($status)->toBe(403)->and(ownerIds($ws))->toBe([$admin->id]);
});

it('refuses to remove a member who became OWNER after the request loaded it', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin] = workspaceWithRoles();
    $stale = memberOf($ws, $admin); // loaded as ADMIN by the "remove" request
    inTenantAsOwner($ws, fn () => app(TransferOwnership::class)->handle($ws, memberOf($ws, $admin), $owner));

    $status = apiStatus(fn () => inTenantAsOwner($ws, fn () => app(RemoveMember::class)->handle($stale, false)));

    expect($status)->toBe(409)->and(ownerIds($ws))->toBe([$admin->id]);
});

it('refuses to change the role of a member who became OWNER after the request loaded it', function () {
    ['workspace' => $ws, 'owner' => $owner, 'admin' => $admin] = workspaceWithRoles();
    $stale = memberOf($ws, $admin);
    inTenantAsOwner($ws, fn () => app(TransferOwnership::class)->handle($ws, memberOf($ws, $admin), $owner));

    $status = apiStatus(fn () => inTenantAsOwner($ws, fn () => app(ChangeMemberRole::class)->handle($stale, WorkspaceRole::Viewer)));

    expect($status)->toBe(409)->and(ownerIds($ws))->toBe([$admin->id]);
});

it('returns 404 when changing the role of a member removed concurrently', function () {
    ['workspace' => $ws, 'editor' => $editor] = workspaceWithRoles();
    $stale = memberOf($ws, $editor);
    inTenantAsOwner($ws, fn () => WorkspaceMember::query()->whereKey($stale->id)->delete());

    expect(apiStatus(fn () => inTenantAsOwner($ws, fn () => app(ChangeMemberRole::class)->handle($stale, WorkspaceRole::Viewer))))->toBe(404);
});
