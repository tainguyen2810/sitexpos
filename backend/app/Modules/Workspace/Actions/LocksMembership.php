<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;

/**
 * Serialises membership mutations of one workspace. Every action that changes
 * roles or removes members must call these helpers inside a DB transaction:
 * the workspace row is always locked first (single lock order → no deadlock),
 * then members are re-read under lock so decisions are never taken on a stale
 * model loaded earlier in the request (PHASE1-AUDIT C11/C12).
 */
trait LocksMembership
{
    private function lockWorkspace(string $workspaceId): void
    {
        Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first(['id']);
    }

    /**
     * Re-reads the member under lock; 404 if it disappeared concurrently.
     */
    private function lockMember(WorkspaceMember $member): WorkspaceMember
    {
        return WorkspaceMember::query()->whereKey($member->getKey())->lockForUpdate()->first()
            ?? throw ApiException::notFound();
    }

    private function assertUnchanged(WorkspaceMember $stale, WorkspaceMember $fresh): void
    {
        if ($stale->role !== $fresh->role || $stale->status !== $fresh->status) {
            throw ApiException::conflict('membership_changed', __('api.membership_changed'));
        }
    }
}
