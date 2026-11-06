<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Monitoring;

/**
 * Reads the expiry date of the TLS certificate served for APP_URL (run by
 * the scheduled monitor, never during an HTTP request).
 */
final class SslProbe
{
    /**
     * @return array{expires_at: ?string, checked_at: string, error: ?string}
     */
    public function check(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $result = ['expires_at' => null, 'checked_at' => now()->toIso8601ZuluString(), 'error' => null];
        if (! is_string($host) || ! str_starts_with($url, 'https://')) {
            $result['error'] = 'not_https';

            return $result;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client('ssl://'.$host.':'.$port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($client === false) {
            $result['error'] = 'connect_failed';

            return $result;
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert !== null ? openssl_x509_parse($cert) : false;
        if (! is_array($info) || ! isset($info['validTo_time_t'])) {
            $result['error'] = 'no_certificate';

            return $result;
        }
        $result['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $info['validTo_time_t']);

        return $result;
    }
}
