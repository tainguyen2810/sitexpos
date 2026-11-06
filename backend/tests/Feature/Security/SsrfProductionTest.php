<?php

use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;

it('ignores the dev-only SITEX_SSRF_ALLOW_HTTP / allow_hosts switches in production', function () {
    config(['sitex.ssrf.allow_http' => true, 'sitex.ssrf.allow_hosts' => ['127.0.0.1']]);
    app()['env'] = 'production';

    $guard = SsrfGuard::fromConfig();

    expect(fn () => $guard->assertSafe('http://example.com/wp-json'))->toThrow(SsrfViolation::class, 'scheme_not_allowed')
        ->and(fn () => $guard->assertSafe('https://127.0.0.1/wp-json'))->toThrow(SsrfViolation::class, 'private_address');
});
