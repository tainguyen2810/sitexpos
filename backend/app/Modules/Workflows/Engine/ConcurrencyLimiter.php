<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Per-workspace concurrency limit implemented as N cache-lock "slots"
 * (atomic in Redis). A slot expires after the lease, so a killed worker
 * never blocks its tenant forever.
 */
final class ConcurrencyLimiter
{
    public function limit(): int
    {
        return max(1, (int) config('sitex.workflows.tenant_concurrency', 2));
    }

    public function acquire(string $workspaceId, int $ttlSeconds): ?Lock
    {
        for ($i = 0; $i < $this->limit(); $i++) {
            $lock = Cache::lock($this->key($workspaceId, $i), $ttlSeconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    public function key(string $workspaceId, int $slot): string
    {
        return "wf:slot:ws:{$workspaceId}:{$slot}";
    }
}
