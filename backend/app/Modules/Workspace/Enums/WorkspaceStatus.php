<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Enums;

enum WorkspaceStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case PendingDeletion = 'pending_deletion';
}
