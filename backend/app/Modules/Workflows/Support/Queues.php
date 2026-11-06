<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Support;

/**
 * Every queue name used by jobs. Horizon (config/horizon.php) must listen to
 * all of them — tests/Unit/Workflows/QueueConfigTest.php enforces it.
 */
final class Queues
{
    public const ALL = [
        'critical', 'default', 'notifications', 'billing',
        'connector', 'publishing',
        'research', 'writing', 'factcheck',
        'media',
        'analytics',
    ];
}
