<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Notification;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Notifications\WorkspaceInvitationNotification;

/**
 * Tenant job: runs inside the dispatching workspace's context. The plain
 * token travels only inside the encrypted job payload, never in the DB.
 */
final class SendWorkspaceInvitation extends TenantJob implements ShouldBeEncrypted
{
    public int $tries = 3;

    public function __construct(public string $invitationId, public string $token)
    {
        parent::__construct();
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        // Scoped to the current tenant: an id from another workspace resolves to null.
        $invitation = WorkspaceInvitation::query()->with(['workspace', 'inviter'])->find($this->invitationId);

        if ($invitation === null || ! $invitation->isPending()) {
            return;
        }

        Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitationNotification($invitation, $this->token));
    }
}
