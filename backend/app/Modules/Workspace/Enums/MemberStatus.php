<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
