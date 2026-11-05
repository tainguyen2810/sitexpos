<?php

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use SiteXPost\Kernel\Tenancy\MissingTenantContextException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;
use SiteXPost\Modules\Workspace\Jobs\SendWorkspaceInvitation;
use SiteXPost\Modules\Workspace\Notifications\WorkspaceInvitationNotification;

function makeInvitation($workspace, string $email): WorkspaceInvitation
{
    return app(TenantContext::class)->runAs($workspace, null, function () use ($workspace, $email) {
        $inv = new WorkspaceInvitation;
        $inv->forceFill([
            'email' => $email, 'role' => WorkspaceRole::Viewer, 'token_hash' => hash('sha256', $email),
            'invited_by' => $workspace->owner_id, 'expires_at' => now()->addDay(),
        ])->save();

        return $inv;
    });
}

it('captures the workspace from the server-side context at dispatch', function () {
    Queue::fake();
    $ws = createWorkspace();
    $inv = makeInvitation($ws, 'q@example.com');

    app(TenantContext::class)->runAs($ws, null, fn () => SendWorkspaceInvitation::dispatch($inv->id, str_repeat('t', 64)));

    Queue::assertPushed(SendWorkspaceInvitation::class, fn ($job) => $job->workspaceId === $ws->id);
});

it('cannot be dispatched outside a tenant context', function () {
    SendWorkspaceInvitation::dispatch('x', 'y');
})->throws(MissingTenantContextException::class);

it('restores the tenant context when the job runs and cannot read another tenant', function () {
    Notification::fake();
    $a = createWorkspace(name: 'A');
    $b = createWorkspace(name: 'B');
    $invB = makeInvitation($b, 'b@example.com');

    // A job dispatched for tenant A that references tenant B's invitation id finds nothing.
    $job = app(TenantContext::class)->runAs($a, null, fn () => new SendWorkspaceInvitation($invB->id, str_repeat('t', 64)));
    dispatch($job);
    Notification::assertNothingSent();

    $jobB = app(TenantContext::class)->runAs($b, null, fn () => new SendWorkspaceInvitation($invB->id, str_repeat('t', 64)));
    dispatch($jobB);
    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);
});

it('skips jobs whose workspace was suspended in the meantime', function () {
    Notification::fake();
    $ws = createWorkspace();
    $inv = makeInvitation($ws, 's@example.com');
    $job = app(TenantContext::class)->runAs($ws, null, fn () => new SendWorkspaceInvitation($inv->id, str_repeat('t', 64)));

    $ws->forceFill(['status' => WorkspaceStatus::Suspended])->save();
    dispatch($job);

    Notification::assertNothingSent();
});
