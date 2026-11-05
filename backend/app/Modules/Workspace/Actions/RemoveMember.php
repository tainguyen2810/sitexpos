<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class RemoveMember
{
    public function handle(WorkspaceMember $member, bool $isSelf): void
    {
        if ($member->role === WorkspaceRole::Owner) {
            throw ApiException::conflict('owner_cannot_leave', __('api.owner_cannot_leave'));
        }

        $userId = $member->user_id;
        $role = $member->role->value;
        $member->delete();

        Audit::record($isSelf ? 'workspace.member.left' : 'workspace.member.removed', $member, [
            'user_id' => $userId,
            'role' => $role,
        ]);
    }
}
