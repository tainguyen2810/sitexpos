<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Enums;

enum SiteStatus: string
{
    /** Created on Cloud, waiting for the WordPress admin to pair the plugin. */
    case PendingPairing = 'pending_pairing';
    /** Paired and the last health check succeeded. */
    case Connected = 'connected';
    /** Paired but the last health check failed (site down, plugin disabled...). */
    case Degraded = 'degraded';
    /** Credentials revoked from Cloud or WordPress: no read or write is possible. */
    case Revoked = 'revoked';

    public function isPaired(): bool
    {
        return $this === self::Connected || $this === self::Degraded;
    }
}
