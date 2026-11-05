<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
