<?php
/**
 * Plugin signer unit test (no WordPress needed). Uses the same test vector as
 * backend/tests/Unit/Sites/SignerTest.php, so Cloud and plugin are proven to
 * produce identical canonical strings and signatures.
 *
 *   php tests/unit/SignerTest.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../sitex-post/includes/Security/Signer.php';

use SiteXPost\WP\Security\Signer;

$failures = 0;
function check( $name, $ok ) {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$name}\n";
	if ( ! $ok ) {
		$failures++;
	}
}

$secret = 'c2l0ZXgtdGVzdC1zZWNyZXQtMzItYnl0ZXMtbG9uZyEh';
$nonce  = 'bm9uY2Utbm9uY2Utbm9uY2Ux';

$canonical = Signer::canonical( 'c2w', 'post', '/sitex-post/v1/posts', array( 'b' => 'x y', 'a' => '1', 'c' => true ), 1762300000, $nonce, '{"title":"Xin chào"}' );
check(
	'canonical string matches the documented format',
	implode( "\n", array( 'SITEX-HMAC-V1', 'c2w', 'POST', '/sitex-post/v1/posts', 'a=1&b=x%20y&c=1', '1762300000', $nonce, hash( 'sha256', '{"title":"Xin chào"}' ) ) ) === $canonical
);

$health = Signer::canonical( 'c2w', 'GET', '/sitex-post/v1/health', array(), 1762300000, $nonce, '' );
check( 'shared test vector signature (same as Cloud)', 'xu0pytFD0cJmAe1x5aMWPfDCzRF0P1WCycOkXlo4a3c=' === Signer::sign( $health, $secret ) );

$sig = Signer::sign( $health, $secret );
check( 'verify accepts the right signature', Signer::verify( $health, $secret, $sig ) );
check( 'verify rejects another direction', ! Signer::verify( Signer::canonical( 'w2c', 'GET', '/sitex-post/v1/health', array(), 1762300000, $nonce, '' ), $secret, $sig ) );
check( 'verify rejects another body', ! Signer::verify( Signer::canonical( 'c2w', 'GET', '/sitex-post/v1/health', array(), 1762300000, $nonce, 'x' ), $secret, $sig ) );
check( 'verify rejects another timestamp', ! Signer::verify( Signer::canonical( 'c2w', 'GET', '/sitex-post/v1/health', array(), 1762300001, $nonce, '' ), $secret, $sig ) );
check( 'verify rejects another secret', ! Signer::verify( $health, 'b3RoZXItc2VjcmV0LW90aGVyLXNlY3JldC1vdGhlciEh', $sig ) );
check( 'nested query parameters are never signed', 'a=1' === Signer::canonical_query( array( 'a' => '1', 'x' => array( 'y' => '2' ) ) ) );
check( 'response canonical binds the request nonce', Signer::response_canonical( $nonce, 1762300000, '{}' ) === implode( "\n", array( 'SITEX-HMAC-V1', 'resp', $nonce, '1762300000', hash( 'sha256', '{}' ) ) ) );
check( 'generated nonces are valid', Signer::is_valid_nonce( Signer::nonce() ) );
check( 'short nonces are rejected', ! Signer::is_valid_nonce( 'short' ) );

echo $failures ? "{$failures} failed\n" : "all passed\n";
exit( $failures ? 1 : 0 );
