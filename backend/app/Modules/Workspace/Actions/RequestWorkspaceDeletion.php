<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Marks the workspace for deletion. Data is hard-deleted later by a
 * retention job (Phase 10); until then the workspace is inaccessible.
 */
final class RequestWorkspaceDeletion
{
    public function handle(Workspace $workspace): void
    {
        $workspace->forceFill(['status' => WorkspaceStatus::PendingDeletion])->save();
        Audit::record('workspace.deletion_requested', $workspace);
    }
}
