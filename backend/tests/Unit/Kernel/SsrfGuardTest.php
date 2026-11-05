<?php

use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;

dataset('blocked_ips', [
    '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1',
]);

it('blocks private, loopback, link-local and metadata addresses', function (string $ip) {
    expect(SsrfGuard::isBlockedIp($ip))->toBeTrue();
})->with('blocked_ips');

it('allows public addresses', function (string $ip) {
    expect(SsrfGuard::isBlockedIp($ip))->toBeFalse();
})->with(['8.8.8.8', '1.1.1.1', '172.32.0.1', '2606:4700:4700::1111']);

it('rejects hostnames that resolve to private IPs (DNS rebinding pinning)', function () {
    $guard = new SsrfGuard(resolver: fn () => ['93.184.216.34', '10.0.0.5']);
    $guard->assertSafe('https://evil.example.com/');
})->throws(SsrfViolation::class, 'private_address');

it('returns the pinned IP for a safe URL', function () {
    $guard = new SsrfGuard(resolver: fn () => ['93.184.216.34']);

    expect($guard->assertSafe('https://example.com/wp-json'))
        ->toMatchArray(['host' => 'example.com', 'port' => 443, 'ip' => '93.184.216.34']);
});

it('rejects non-https, odd ports and embedded credentials', function (string $url, string $reason) {
    $guard = new SsrfGuard(resolver: fn () => ['93.184.216.34']);

    expect(fn () => $guard->assertSafe($url))->toThrow(SsrfViolation::class, $reason);
})->with([
    ['http://example.com', 'scheme_not_allowed'],
    ['file:///etc/passwd', 'invalid_url'],
    ['https://example.com:8443', 'port_not_allowed'],
    ['https://user:pass@example.com', 'credentials_in_url'],
    ['https://127.0.0.1', 'private_address'],
]);
