<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

final class TransferOwnership
{
    use LocksMembership;

    /**
     * @param  User|null  $actor  the user performing the transfer; when given, it must still be the OWNER under lock
     */
    public function handle(Workspace $workspace, WorkspaceMember $newOwner, ?User $actor = null): void
    {
        DB::transaction(function () use ($workspace, $newOwner, $actor): void {
            $this->lockWorkspace($workspace->id);

            $previousOwner = $workspace->members()
                ->where('role', WorkspaceRole::Owner)
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor !== null && $previousOwner->user_id !== $actor->id) {
                // Ownership moved concurrently: the caller is no longer the OWNER.
                throw ApiException::forbidden();
            }

            // Re-read the target under lock: it may have been removed or changed meanwhile.
            $target = $this->lockMember($newOwner);

            if ($target->role === WorkspaceRole::Owner) {
                throw ApiException::conflict('already_owner', __('api.already_owner'));
            }

            if ($target->status !== MemberStatus::Active) {
                throw ApiException::conflict('member_not_active', __('api.member_not_active'));
            }

            $previousOwner->forceFill(['role' => WorkspaceRole::Admin])->save();
            $target->forceFill(['role' => WorkspaceRole::Owner])->save();
            $workspace->forceFill(['owner_id' => $target->user_id])->save();

            // Invariant: exactly one OWNER, and it is the workspace owner_id.
            $owners = $workspace->members()->where('role', WorkspaceRole::Owner)->pluck('user_id')->all();
            if ($owners !== [$target->user_id]) {
                throw new LogicException('Ownership invariant violated during transfer.');
            }

            Audit::record('workspace.ownership_transferred', $workspace, [
                'from_user_id' => $previousOwner->user_id,
                'to_user_id' => $target->user_id,
            ]);

        });
    }
}
