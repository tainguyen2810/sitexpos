<?php

use SiteXPost\Kernel\Logging\RedactSecrets;

it('redacts secret-looking keys recursively', function () {
    $out = RedactSecrets::redact([
        'email' => 'a@b.c',
        'password' => 'x',
        'nested' => ['api_key' => 'y', 'Authorization' => 'Bearer z', 'ok' => 1],
        'two_factor_recovery_codes' => '...',
    ]);

    expect($out)->toBe([
        'email' => 'a@b.c',
        'password' => '[REDACTED]',
        'nested' => ['api_key' => '[REDACTED]', 'Authorization' => '[REDACTED]', 'ok' => 1],
        'two_factor_recovery_codes' => '[REDACTED]',
    ]);
});
