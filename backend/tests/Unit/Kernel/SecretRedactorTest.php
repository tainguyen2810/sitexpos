<?php

use SiteXPost\Kernel\Logging\SecretRedactor;

it('removes configured provider keys and common key formats', function () {
    config(['sitex.ai.providers.openai.api_key' => 'my-configured-secret-123']);

    $text = 'failed my-configured-secret-123 sk-ant-api03-abcdefghijkl sk-proj-ABCDEFGHIJKLMNOPQRST '
        .'AIzaSyA1234567890abcdefghijk https://x.test/v1?key=raw-query-key Authorization: Bearer abc.def-ghi';
    $out = SecretRedactor::redact($text);

    expect($out)->not->toContain('my-configured-secret-123')
        ->not->toContain('sk-ant-api03')
        ->not->toContain('sk-proj-ABCDEFGH')
        ->not->toContain('AIzaSyA123')
        ->not->toContain('raw-query-key')
        ->not->toContain('abc.def-ghi')
        ->toContain('[REDACTED]');
});

it('truncates long messages', function () {
    expect(mb_strlen(SecretRedactor::redact(str_repeat('a', 2000))))->toBe(500);
});
