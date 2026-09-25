<?php
/**
 * Phone display formatter (GX0): the phone is stored as E.164 for tel: links;
 * visible text reads in national format — "(902) 555-0100" for North American
 * numbers, a grouped international form elsewhere — and text the operator
 * formatted by hand is left alone.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneFormatTest extends TestCase {

	private string $base_country = 'CA:NS';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias( fn( $key, $fallback = false ) => 'woocommerce_default_country' === $key ? $this->base_country : $fallback );
		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-phone-format.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function numbers(): array {
		return array(
			'NANP E.164'                  => array( '+19025550100', '', '(902) 555-0100' ),
			'NANP E.164, foreign store'   => array( '+12125550100', 'GB', '(212) 555-0100' ),
			'NANP 10 digits, CA store'    => array( '9025550100', 'CA', '(902) 555-0100' ),
			'NANP 11 digits, US store'    => array( '12125550100', 'US', '(212) 555-0100' ),
			'UK E.164'                    => array( '+442079460958', '', '+44 207 946 0958' ),
			'France E.164'                => array( '+33142685300', '', '+33 142 685 300' ),
			'Ireland (3-digit code)'      => array( '+35312345678', '', '+353 1234 5678' ),
			'operator-formatted text'     => array( '902-555-0100 ext. 2', '', '902-555-0100 ext. 2' ),
			'already national'            => array( '(902) 555-0100', '', '(902) 555-0100' ),
			'empty'                       => array( '', '', '' ),
			'10 digits, non-NANP country' => array( '0612345678', 'FR', '0612345678' ),
		);
	}

	#[DataProvider( 'numbers' )]
	public function test_display_format( string $raw, string $country, string $expected ): void {
		$this->assertSame( $expected, lafka_format_phone_display( $raw, $country ) );
	}

	public function test_bare_numbers_are_recognised(): void {
		$this->assertTrue( lafka_phone_is_bare_number( '+19025550100' ) );
		$this->assertTrue( lafka_phone_is_bare_number( '9025550100' ) );
		$this->assertFalse( lafka_phone_is_bare_number( '(902) 555-0100' ) );
		$this->assertFalse( lafka_phone_is_bare_number( '555' ) );
	}

	public function test_the_display_format_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value, $raw = '', $country = '' ) => 'lafka_format_phone_display' === $hook ? "{$raw}|{$country}" : $value
		);

		$this->assertSame( '+19025550100|CA', lafka_format_phone_display( '+19025550100' ) );
	}
}
