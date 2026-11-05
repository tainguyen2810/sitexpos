<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

/**
 * Authorization (who may assign which role) is enforced by WorkspaceMemberPolicy
 * on the model loaded by the request; the action re-checks that the member did
 * not change in between (e.g. became OWNER through a concurrent transfer).
 */
final class ChangeMemberRole
{
    use LocksMembership;

    public function handle(WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember
    {
        return DB::transaction(function () use ($member, $role): WorkspaceMember {
            $this->lockWorkspace($member->workspace_id);
            $fresh = $this->lockMember($member);
            $this->assertUnchanged($member, $fresh);

            $from = $fresh->role;
            if ($from === $role) {
                return $fresh;
            }

            $fresh->forceFill(['role' => $role])->save();
            Audit::record('workspace.member.role_changed', $fresh, [
                'user_id' => $fresh->user_id,
                'from' => $from->value,
                'to' => $role->value,
            ]);

            return $fresh;
        });
    }
}
