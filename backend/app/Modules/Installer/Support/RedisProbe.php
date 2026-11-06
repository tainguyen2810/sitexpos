<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

/**
 * Minimal RESP client (AUTH + PING + INFO server) over a socket, so the
 * installer can test Redis before any Redis client is configured and without
 * requiring phpredis/predis. Never logs the password.
 */
final class RedisProbe
{
    /**
     * @return array{connected: bool, error_code: ?string, version: ?string}
     */
    public function ping(string $host, int $port, ?string $password, float $timeout = 3.0): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return ['connected' => false, 'error_code' => 'host_unreachable', 'version' => null];
        }
        stream_set_timeout($socket, (int) ceil($timeout));

        try {
            if ($password !== null && $password !== '') {
                $reply = $this->command($socket, ['AUTH', $password]);
                if (! str_starts_with($reply, '+OK')) {
                    return ['connected' => false, 'error_code' => 'auth_failed', 'version' => null];
                }
            }
            $pong = $this->command($socket, ['PING']);
            if (str_starts_with($pong, '-NOAUTH')) {
                return ['connected' => false, 'error_code' => 'auth_required', 'version' => null];
            }
            if (! str_starts_with($pong, '+PONG')) {
                return ['connected' => false, 'error_code' => 'unexpected_reply', 'version' => null];
            }
            $info = $this->command($socket, ['INFO', 'server']);
            $version = preg_match('/redis_version:([0-9.]+)/', $info, $m) ? $m[1] : null;

            return ['connected' => true, 'error_code' => null, 'version' => $version];
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     * @param  list<string>  $args
     */
    private function command($socket, array $args): string
    {
        $payload = '*'.count($args)."\r\n";
        foreach ($args as $a) {
            $payload .= '$'.strlen($a)."\r\n".$a."\r\n";
        }
        fwrite($socket, $payload);
        $line = (string) fgets($socket);
        if (str_starts_with($line, '$')) {
            $len = (int) substr($line, 1);
            $data = '';
            while ($len > 0 && strlen($data) < $len + 2 && ! feof($socket)) {
                $chunk = fread($socket, $len + 2 - strlen($data));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            }

            return $data;
        }

        return $line;
    }
}
