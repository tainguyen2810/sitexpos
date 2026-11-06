<?php

use SiteXPost\Modules\Sites\Connector\Signer;

/*
 * Shared test vector: wordpress-plugin/tests/unit/SignerTest.php asserts the
 * exact same canonical string and signature, proving both implementations
 * are byte-for-byte compatible.
 */
const SITEX_VECTOR_SECRET = 'c2l0ZXgtdGVzdC1zZWNyZXQtMzItYnl0ZXMtbG9uZyEh';

it('builds the documented canonical string', function () {
    $canonical = Signer::canonical('c2w', 'post', '/sitex-post/v1/posts', ['b' => 'x y', 'a' => '1', 'c' => true], 1762300000, 'bm9uY2Utbm9uY2Utbm9uY2Ux', '{"title":"Xin chào"}');

    expect($canonical)->toBe(implode("\n", [
        'SITEX-HMAC-V1',
        'c2w',
        'POST',
        '/sitex-post/v1/posts',
        'a=1&b=x%20y&c=1',
        '1762300000',
        'bm9uY2Utbm9uY2Utbm9uY2Ux',
        hash('sha256', '{"title":"Xin chào"}'),
    ]));
});

it('matches the shared cross-implementation test vector (independently computed with Python hmac)', function () {
    $canonical = Signer::canonical('c2w', 'GET', '/sitex-post/v1/health', [], 1762300000, 'bm9uY2Utbm9uY2Utbm9uY2Ux', '');

    expect(Signer::sign($canonical, SITEX_VECTOR_SECRET))->toBe('xu0pytFD0cJmAe1x5aMWPfDCzRF0P1WCycOkXlo4a3c=');
});

it('verifies and detects any tampering', function () {
    $secret = 'c2l0ZXgtdGVzdC1zZWNyZXQtMzItYnl0ZXMtbG9uZyEh';
    $base = fn (array $o = []) => Signer::canonical(
        $o['dir'] ?? 'c2w', $o['method'] ?? 'POST', $o['path'] ?? '/sitex-post/v1/posts', $o['query'] ?? [], $o['ts'] ?? 1762300000, $o['nonce'] ?? 'bm9uY2Utbm9uY2Utbm9uY2Ux', $o['body'] ?? '{}',
    );
    $sig = Signer::sign($base(), $secret);

    expect(Signer::verify($base(), $secret, $sig))->toBeTrue()
        ->and(Signer::verify($base(['dir' => 'w2c']), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(['method' => 'PUT']), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(['path' => '/sitex-post/v1/media']), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(['query' => ['x' => '1']]), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(['ts' => 1762300001]), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(['nonce' => 'b3RoZXItbm9uY2Utb3RoZXIx']), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(['body' => '{"a":1}']), $secret, $sig))->toBeFalse()
        ->and(Signer::verify($base(), 'b3RoZXItc2VjcmV0LW90aGVyLXNlY3JldC1vdGhlciEh', $sig))->toBeFalse();
});

it('validates nonce format', function () {
    expect(Signer::isValidNonce(Signer::nonce()))->toBeTrue()
        ->and(Signer::isValidNonce('short'))->toBeFalse()
        ->and(Signer::isValidNonce(str_repeat('a', 30).'!'))->toBeFalse();
});
