<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Enums;

/**
 * Tenant permissions. Role → permission matrix lives in WorkspaceRole
 * (SECURITY.md §3.2). Permissions for later phases are declared now so the
 * matrix is stable, but no Phase 1 endpoint uses them yet.
 */
enum Permission: string
{
    case WorkspaceView = 'workspace.view';
    case WorkspaceUpdate = 'workspace.update';
    case WorkspaceDelete = 'workspace.delete';
    case WorkspaceTransfer = 'workspace.transfer';
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case AuditView = 'audit.view';
    case BillingManage = 'billing.manage';
    case SitesManage = 'sites.manage';
    case ContentEdit = 'content.edit';
    case ContentPublish = 'content.publish';

    public function isReadOnly(): bool
    {
        return in_array($this, [self::WorkspaceView, self::MembersView, self::AuditView], true);
    }
}
