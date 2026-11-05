<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class RemoveMember
{
    use LocksMembership;

    public function handle(WorkspaceMember $member, bool $isSelf): void
    {
        DB::transaction(function () use ($member, $isSelf): void {
            $this->lockWorkspace($member->workspace_id);
            $fresh = $this->lockMember($member);

            // Checked on the locked row: the member may have become OWNER via a concurrent transfer.
            if ($fresh->role === WorkspaceRole::Owner) {
                throw ApiException::conflict('owner_cannot_leave', __('api.owner_cannot_leave'));
            }

            // The policy authorised the action for the role it saw; refuse if it changed since.
            $this->assertUnchanged($member, $fresh);

            $userId = $fresh->user_id;
            $role = $fresh->role->value;
            $fresh->delete();

            Audit::record($isSelf ? 'workspace.member.left' : 'workspace.member.removed', $fresh, [
                'user_id' => $userId,
                'role' => $role,
            ]);
        });
    }
}
