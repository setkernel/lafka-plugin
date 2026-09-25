<?php
/**
 * GX1 / A2: the log PII scrubber masks customer data by key and by value
 * pattern, keeps merchant record ids, and bounds depth / length / size.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Log_Scrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';

final class LogScrubberTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function pii_strings(): array {
		return array(
			'email'             => array( 'Contact jane.doe+pizza@example.com now', 'jane.doe', '[email]' ),
			'nanp phone dashed' => array( 'Call 902-555-0142 please', '555-0142', '[phone]' ),
			'nanp phone parens' => array( 'Call (902) 555-0142', '555-0142', '[phone]' ),
			'e164 phone'        => array( 'SMS +19025550142 sent', '9025550142', '[phone]' ),
			'visa pan'          => array( 'card 4111 1111 1111 1111 declined', '4111', '[card]' ),
			'amex pan'          => array( 'pan 378282246310005 x', '378282246310005', '[card]' ),
			'canadian postcode' => array( 'Deliver to B3H 4R2 today', 'B3H 4R2', '[postcode]' ),
			'uk postcode'       => array( 'to SW1A 1AA ok', 'SW1A 1AA', '[postcode]' ),
			'ipv4'              => array( 'from 203.0.113.42 blocked', '203.0.113.42', '[ip]' ),
			'ipv6'              => array( 'from 2001:db8:85a3::8a2e:370:7334 ok', '2001:db8', '[ip]' ),
			'bearer'            => array( 'Authorization: Bearer abc.DEF-123_xyz', 'abc.DEF-123_xyz', '[token]' ),
			'jwt'               => array( 'tok eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig_nat-ure end', 'eyJhbGci', '[token]' ),
			'coordinates'       => array( 'pinned 44.648763, -63.575238 here', '44.648763', '44.65, -63.58' ),
		);
	}

	#[DataProvider( 'pii_strings' )]
	public function test_pii_in_strings_is_masked( string $input, string $leak, string $mask ): void {
		$out = Lafka_Log_Scrubber::scrub_string( $input );

		self::assertStringNotContainsString( $leak, $out );
		self::assertStringContainsString( $mask, $out );
	}

	public function test_non_pii_strings_survive(): void {
		$safe = 'Order #9741 total 23.45 at 20:22:14 via Lafka_Store_Api::register (HTTP 409)';

		self::assertSame( $safe, Lafka_Log_Scrubber::scrub_string( $safe ) );
	}

	public function test_long_digit_runs_that_fail_luhn_are_not_cards(): void {
		// A 12-digit gateway transaction id is not a card and not a phone.
		$out = Lafka_Log_Scrubber::scrub_string( 'Transaction ID 121527375877' );

		self::assertSame( 'Transaction ID 121527375877', $out );
	}

	public function test_denylisted_keys_are_redacted_at_any_depth(): void {
		$out = Lafka_Log_Scrubber::scrub(
			array(
				'order_id'   => 9741,
				'product_id' => 33,
				'email'      => 'a@b.co',
				'billing'    => array(
					'billing_phone' => '9025550142',
					'first_name'    => 'Jane',
				),
				'nested'     => array( 'kds_token' => 'abc', 'resume_token' => 'zzz', 'Authorization' => 'x' ),
				'address_1'  => '1 Main St',
				'postcode'   => 'B3H4R2',
				'card_last4' => '4242',
				'cvv'        => '123',
				'ip'         => '203.0.113.9',
				'user_agent' => 'Mozilla',
				'nonce'      => 'n',
				'password'   => 'p',
				'cookie'     => 'c',
			)
		);

		self::assertSame( 9741, $out['order_id'] );
		self::assertSame( 33, $out['product_id'] );
		foreach ( array( 'email', 'address_1', 'postcode', 'card_last4', 'cvv', 'ip', 'user_agent', 'nonce', 'password', 'cookie' ) as $key ) {
			self::assertSame( '[redacted]', $out[ $key ], $key );
		}
		self::assertSame( '[redacted]', $out['billing']['billing_phone'] );
		self::assertSame( '[redacted]', $out['billing']['first_name'] );
		self::assertSame( '[redacted]', $out['nested']['kds_token'] );
		self::assertSame( '[redacted]', $out['nested']['resume_token'] );
		self::assertSame( '[redacted]', $out['nested']['Authorization'] );
	}

	public function test_values_under_safe_keys_are_still_pattern_masked(): void {
		$out = Lafka_Log_Scrubber::scrub( array( 'note' => 'customer jane@example.com called 902-555-0142' ) );

		self::assertStringNotContainsString( 'jane@example.com', $out['note'] );
		self::assertStringNotContainsString( '555-0142', $out['note'] );
	}

	public function test_coordinate_keys_are_rounded(): void {
		$out = Lafka_Log_Scrubber::scrub( array( 'lat' => 44.648763, 'lng' => '-63.575238' ) );

		self::assertSame( 44.65, $out['lat'] );
		self::assertSame( -63.58, $out['lng'] );
	}

	public function test_depth_is_capped(): void {
		$out = Lafka_Log_Scrubber::scrub( array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => 'deep' ) ) ) ) ) );

		self::assertSame( '[depth]', $out['a']['b']['c']['d'] );
	}

	public function test_long_strings_are_truncated(): void {
		$out = Lafka_Log_Scrubber::scrub( array( 'm' => str_repeat( 'x', 900 ) ) );

		self::assertLessThanOrEqual( 501, mb_strlen( $out['m'] ) );
	}

	public function test_total_context_size_is_bounded(): void {
		$context = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$context[ 'k' . $i ] = str_repeat( 'y', 400 );
		}

		$out = Lafka_Log_Scrubber::scrub( $context );

		self::assertLessThanOrEqual( Lafka_Log_Scrubber::MAX_CONTEXT_BYTES + 64, strlen( (string) json_encode( $out ) ) );
		self::assertTrue( $out['_truncated'] );
	}

	public function test_throwables_become_safe_summaries(): void {
		$out = Lafka_Log_Scrubber::scrub( array( 'exception' => new \RuntimeException( 'failed for bob@example.com' ) ) );

		self::assertSame( 'RuntimeException', $out['exception']['class'] );
		self::assertStringNotContainsString( 'bob@example.com', $out['exception']['message'] );
		self::assertArrayHasKey( 'file', $out['exception'] );
	}

	public function test_objects_are_reduced_to_their_class_name(): void {
		$out = Lafka_Log_Scrubber::scrub( array( 'obj' => new \ArrayObject( array( 'email' => 'a@b.co' ) ) ) );

		self::assertSame( '[object ArrayObject]', $out['obj'] );
	}

	public function test_key_list_is_extensible_by_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'lafka_log_scrub_keys' === $hook ) {
					$value[] = 'loyalty_number';
				}
				return $value;
			}
		);

		$out = Lafka_Log_Scrubber::scrub( array( 'loyalty_number' => 'L-1' ) );

		self::assertSame( '[redacted]', $out['loyalty_number'] );
	}
}
