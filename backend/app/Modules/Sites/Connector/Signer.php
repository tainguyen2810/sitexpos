<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Connector;

/**
 * SITEX-HMAC-V1 request signing shared by Cloud and the WordPress plugin.
 * The plugin has a byte-for-byte compatible implementation
 * (wordpress-plugin/sitex-post/includes/Security/Signer.php); both are
 * covered by the same test vector. See SECURITY.md §6.2.
 *
 * Canonical string (lines joined by "\n"):
 *   SITEX-HMAC-V1
 *   {direction}        c2w (Cloud → WordPress) | w2c (WordPress → Cloud) | resp
 *   {METHOD}
 *   {path}             REST route (/sitex-post/v1/health) or Cloud path (/connector/v1/heartbeat)
 *   {canonical query}  keys sorted, RFC 3986 encoded
 *   {timestamp}        Unix seconds
 *   {nonce}            16+ random bytes, base64url
 *   {hex sha256(body)}
 *
 * The direction label stops a captured request from being replayed in the
 * opposite direction with the same key.
 */
final class Signer
{
    public const VERSION = 'SITEX-HMAC-V1';

    public const DIRECTION_TO_WP = 'c2w';

    public const DIRECTION_TO_CLOUD = 'w2c';

    public const DIRECTION_RESPONSE = 'resp';

    public const HEADER_KEY = 'X-SiteX-Key-Id';

    public const HEADER_TIMESTAMP = 'X-SiteX-Timestamp';

    public const HEADER_NONCE = 'X-SiteX-Nonce';

    public const HEADER_SIGNATURE = 'X-SiteX-Signature';

    /**
     * @param  array<string, scalar|null>  $query
     */
    public static function canonical(string $direction, string $method, string $path, array $query, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            self::VERSION,
            $direction,
            strtoupper($method),
            '/'.ltrim($path, '/'),
            self::canonicalQuery($query),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                continue; // nested parameters are never signed nor accepted
            }
            $pairs[(string) $key] = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        ksort($pairs, SORT_STRING);

        $out = [];
        foreach ($pairs as $key => $value) {
            $out[] = rawurlencode($key).'='.rawurlencode($value);
        }

        return implode('&', $out);
    }

    public static function sign(string $canonical, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $canonical, self::decodeSecret($secret), true));
    }

    public static function verify(string $canonical, string $secret, string $signature): bool
    {
        return hash_equals(self::sign($canonical, $secret), $signature);
    }

    /**
     * Response signature binds the response body to the request nonce.
     */
    public static function responseCanonical(string $requestNonce, int $timestamp, string $body): string
    {
        return implode("\n", [self::VERSION, self::DIRECTION_RESPONSE, $requestNonce, (string) $timestamp, hash('sha256', $body)]);
    }

    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public static function isValidNonce(string $nonce): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{22,64}$/', $nonce) === 1;
    }

    private static function decodeSecret(string $secret): string
    {
        $raw = base64_decode(strtr($secret, '-_', '+/'), true);

        return $raw === false ? $secret : $raw;
    }
}
