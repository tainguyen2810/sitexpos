<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Security;

/**
 * Validates outbound URLs supplied by customers or fetched content
 * (WordPress sites, research sources, images) before any HTTP request.
 *
 * Returns the resolved, verified IP so the caller can pin it
 * (CURLOPT_RESOLVE) and defeat DNS rebinding. See SECURITY.md §7.
 */
final class SsrfGuard
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = ['::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /**
     * @param  list<string>  $allowHosts  development-only allowlist (ignored in production)
     * @param  (\Closure(string): list<string>)|null  $resolver
     */
    public function __construct(
        private readonly array $allowHosts = [],
        private readonly bool $allowHttp = false,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? static function (string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $ips = [];
            foreach ($records as $r) {
                $ip = $r['ip'] ?? $r['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }

            return $ips;
        };
    }

    public static function fromConfig(): self
    {
        $allow = app()->isProduction() ? [] : (array) config('sitex.ssrf.allow_hosts', []);

        return new self(array_values(array_map('strval', $allow)), (bool) config('sitex.ssrf.allow_http', false));
    }

    /**
     * @return array{scheme:string,host:string,port:int,ip:string}
     *
     * @throws SsrfViolation
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsrfViolation('invalid_url');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowHttp)) {
            throw new SsrfViolation('scheme_not_allowed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfViolation('credentials_in_url');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (in_array($host, $this->allowHosts, true)) {
            return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $host];
        }

        if (! in_array($port, [80, 443], true)) {
            throw new SsrfViolation('port_not_allowed');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new SsrfViolation('dns_resolution_failed');
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new SsrfViolation('private_address');
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
