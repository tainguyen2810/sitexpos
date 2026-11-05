<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class TransferOwnership
{
    public function handle(Workspace $workspace, WorkspaceMember $newOwner): void
    {
        if ($newOwner->role === WorkspaceRole::Owner) {
            throw ApiException::conflict('already_owner', __('api.already_owner'));
        }

        if ($newOwner->status !== MemberStatus::Active) {
            throw ApiException::conflict('member_not_active', __('api.member_not_active'));
        }

        DB::transaction(function () use ($workspace, $newOwner): void {
            $previousOwner = $workspace->members()
                ->where('role', WorkspaceRole::Owner)
                ->lockForUpdate()
                ->firstOrFail();

            $previousOwner->forceFill(['role' => WorkspaceRole::Admin])->save();
            $newOwner->forceFill(['role' => WorkspaceRole::Owner])->save();
            $workspace->forceFill(['owner_id' => $newOwner->user_id])->save();

            Audit::record('workspace.ownership_transferred', $workspace, [
                'from_user_id' => $previousOwner->user_id,
                'to_user_id' => $newOwner->user_id,
            ]);
        });
    }
}
