<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Enums;

enum CredentialStatus: string
{
    case Active = 'active';
    /** Replaced by a newer key; still accepted until grace_until. */
    case Rotated = 'rotated';
    case Revoked = 'revoked';
}
