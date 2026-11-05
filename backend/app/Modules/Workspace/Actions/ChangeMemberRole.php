<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

/**
 * Authorization (who may assign which role) is enforced by WorkspaceMemberPolicy.
 */
final class ChangeMemberRole
{
    public function handle(WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember
    {
        $from = $member->role;
        if ($from === $role) {
            return $member;
        }

        $member->forceFill(['role' => $role])->save();
        Audit::record('workspace.member.role_changed', $member, [
            'user_id' => $member->user_id,
            'from' => $from->value,
            'to' => $role->value,
        ]);

        return $member;
    }
}
