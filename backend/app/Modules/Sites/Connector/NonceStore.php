<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Connector;

use Illuminate\Support\Facades\Cache;

/**
 * Anti-replay store: a nonce is accepted once per key within the TTL.
 * Cache::add is atomic on Redis (SET NX EX) and on the database/array stores.
 */
final class NonceStore
{
    public function claim(string $keyId, string $nonce): bool
    {
        $ttl = (int) config('sitex.connector.nonce_ttl', 600);

        return Cache::add('sitex:nonce:'.$keyId.':'.hash('sha256', $nonce), 1, $ttl);
    }
}
