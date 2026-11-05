<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;

/**
 * Per-provider circuit breaker stored in the shared cache (Redis in
 * production, so all workers see the same state).
 *   closed    → calls flow; consecutive health failures are counted
 *   open      → calls are skipped until the cooldown ends (fallback is used)
 *   half_open → one probe call is allowed; success closes, failure re-opens
 */
final class CircuitBreaker
{
    private function cache(): Repository
    {
        return Cache::store();
    }

    public function allows(AiProvider $provider): bool
    {
        $openUntil = (int) $this->cache()->get($this->key($provider, 'open_until'), 0);
        if ($openUntil === 0) {
            return true;
        }
        if ($openUntil > now()->getTimestamp()) {
            return false;
        }

        // Cooldown over: half-open — only one probe at a time.
        return $this->cache()->add($this->key($provider, 'probe'), 1, max(10, $provider->timeout_seconds + 5));
    }

    public function recordSuccess(AiProvider $provider): void
    {
        foreach (['failures', 'open_until', 'probe'] as $k) {
            $this->cache()->forget($this->key($provider, $k));
        }
    }

    public function recordFailure(AiProvider $provider): void
    {
        $failuresKey = $this->key($provider, 'failures');
        $this->cache()->add($failuresKey, 0, 3600);
        $failures = (int) $this->cache()->increment($failuresKey);
        $halfOpen = (int) $this->cache()->get($this->key($provider, 'open_until'), 0) !== 0;

        if ($halfOpen || $failures >= max(1, $provider->circuit_failure_threshold)) {
            $this->cache()->put($this->key($provider, 'open_until'), now()->getTimestamp() + max(1, $provider->circuit_cooldown_seconds), 86400);
            $this->cache()->forget($this->key($provider, 'probe'));
        }
    }

    public function reset(AiProvider $provider): void
    {
        $this->recordSuccess($provider);
    }

    /**
     * @return array{state: string, failures: int, open_until: string|null}
     */
    public function state(AiProvider $provider): array
    {
        $openUntil = (int) $this->cache()->get($this->key($provider, 'open_until'), 0);
        $state = match (true) {
            $openUntil === 0 => 'closed',
            $openUntil > now()->getTimestamp() => 'open',
            default => 'half_open',
        };

        return [
            'state' => $state,
            'failures' => (int) $this->cache()->get($this->key($provider, 'failures'), 0),
            'open_until' => $openUntil > 0 ? gmdate('Y-m-d\TH:i:s\Z', $openUntil) : null,
        ];
    }

    private function key(AiProvider $provider, string $suffix): string
    {
        return 'ai:circuit:'.$provider->code.':'.$suffix;
    }
}
