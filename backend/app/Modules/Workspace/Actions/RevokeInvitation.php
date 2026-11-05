<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;

final class RevokeInvitation
{
    public function handle(WorkspaceInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            throw ApiException::conflict('invitation_not_pending', __('api.invitation_not_pending'));
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record('workspace.invitation.revoked', $invitation, ['email' => $invitation->email]);
    }
}
