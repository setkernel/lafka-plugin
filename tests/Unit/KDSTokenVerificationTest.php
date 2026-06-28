<?php
/**
 * Locks the KDS token verification contract (P2-02a hash-at-rest hardening).
 *
 * Lafka_KDS_Token::matches() must (1) keep validating a legacy plaintext token
 * exactly as before (zero disruption to a live tablet), and (2) validate a
 * hash-at-rest token via HMAC while NEVER accepting the stored digest itself as
 * a credential. Targets the pure helper class directly so it needs no WP runtime.
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-token.php';

final class KDSTokenVerificationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'fixed-test-auth-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_hashed_distinguishes_digest_from_plaintext(): void {
		self::assertTrue( \Lafka_KDS_Token::is_hashed( str_repeat( 'a', 64 ) ) );
		self::assertFalse( \Lafka_KDS_Token::is_hashed( 'aB3dEf0123456789aB3dEf0123456789' ), '32-char raw token is not a digest' );
		self::assertFalse( \Lafka_KDS_Token::is_hashed( str_repeat( 'a', 63 ) ), 'wrong length' );
		self::assertFalse( \Lafka_KDS_Token::is_hashed( str_repeat( 'A', 64 ) ), 'uppercase hex is not the lowercase digest form' );
	}

	public function test_legacy_plaintext_token_validates_unchanged(): void {
		$raw = 'abcdef0123456789abcdef0123456789'; // 32 chars, like wp_generate_password( 32, false )
		self::assertTrue( \Lafka_KDS_Token::matches( $raw, $raw ) );
		self::assertFalse( \Lafka_KDS_Token::matches( $raw, 'wrong-token' ) );
		self::assertFalse( \Lafka_KDS_Token::matches( $raw, '' ) );
	}

	public function test_hashed_token_validates_via_hmac_but_not_the_digest_itself(): void {
		$raw    = 'rawtoken1234567890rawtoken123456';
		$stored = \Lafka_KDS_Token::hash( $raw );
		self::assertTrue( \Lafka_KDS_Token::matches( $stored, $raw ), 'raw token authenticates against its stored hash' );
		self::assertFalse( \Lafka_KDS_Token::matches( $stored, 'rawtoken-wrong' ) );
		self::assertFalse( \Lafka_KDS_Token::matches( $stored, $stored ), 'a leaked stored digest must NOT authenticate' );
	}

	public function test_empty_stored_or_candidate_never_matches(): void {
		self::assertFalse( \Lafka_KDS_Token::matches( '', 'anything' ) );
		self::assertFalse( \Lafka_KDS_Token::matches( 'something', '' ) );
		self::assertFalse( \Lafka_KDS_Token::matches( '', '' ) );
	}

	public function test_hash_is_deterministic_and_lowercase_hex64(): void {
		$a = \Lafka_KDS_Token::hash( 'x' );
		self::assertSame( $a, \Lafka_KDS_Token::hash( 'x' ), 'deterministic' );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a );
		self::assertNotSame( $a, \Lafka_KDS_Token::hash( 'y' ) );
	}
}
