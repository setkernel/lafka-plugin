<?php
/**
 * CustomizerSanitizersTest — locks down the validation logic in
 * Lafka_Customizer_Restaurant_Info's public static sanitizers.
 *
 * These run on every Customizer save for restaurant-info fields and are the
 * only barrier between operator typos and the schema generator. Failure modes
 * tested:
 *   - sanitize_geo: latitude bounds (-180..180), numeric coercion
 *   - sanitize_price_range: $/$$/$$$/$$$$ allowlist with safe default
 *   - sanitize_url_list: filters invalid URLs, preserves valid ones
 *   - sanitize_hours: enforces "HH:MM-HH:MM" or "closed"
 *   - sanitize_business_type: schema.org PascalCase ASCII allowlist
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.6
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Restaurant_Info;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-restaurant-info.php';

final class CustomizerSanitizersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// sanitize_url_list calls esc_url_raw — stub passthrough so we can
		// assert on the URLs the sanitizer emits without booting WP's URL
		// scrubber.
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────────────
	// sanitize_geo — decimal degrees, ±180.0 bounds
	// ────────────────────────────────────────────────────────────────────────

	/** @dataProvider geoValidProvider */
	public function test_sanitize_geo_accepts_valid_values( $input, string $expected ): void {
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_geo( $input ) );
	}

	public function geoValidProvider(): array {
		return array(
			'integer-string'        => array( '40', '40' ),
			'positive-decimal'      => array( '40.7128', '40.7128' ),
			'negative-decimal'      => array( '-74.0060', '-74.006' ),
			'zero'                  => array( '0', '0' ),
			'whitespace-trimmed'    => array( '  45.0  ', '45' ),
			'lower-bound'           => array( '-180', '-180' ),
			'upper-bound'           => array( '180', '180' ),
		);
	}

	/** @dataProvider geoInvalidProvider */
	public function test_sanitize_geo_rejects_invalid_values( $input ): void {
		$this->assertSame( '', Lafka_Customizer_Restaurant_Info::sanitize_geo( $input ) );
	}

	public function geoInvalidProvider(): array {
		return array(
			'empty-string'      => array( '' ),
			'non-numeric'       => array( 'abc' ),
			'over-180'          => array( '200' ),
			'under-minus-180'   => array( '-200.5' ),
			'array-input'       => array( array( 40 ) ),
			'object-input'      => array( new \stdClass() ),
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// sanitize_price_range — $/$$/$$$/$$$$ with safe default of $$
	// ────────────────────────────────────────────────────────────────────────

	/** @dataProvider priceRangeProvider */
	public function test_sanitize_price_range( $input, string $expected ): void {
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_price_range( $input ) );
	}

	public function priceRangeProvider(): array {
		return array(
			'inexpensive'         => array( '$', '$' ),
			'moderate'            => array( '$$', '$$' ),
			'expensive'           => array( '$$$', '$$$' ),
			'very-expensive'      => array( '$$$$', '$$$$' ),
			'whitespace-padding'  => array( '  $$  ', '$$' ),
			'invalid-defaults-to-moderate' => array( '$$$$$', '$$' ),
			'empty-defaults-to-moderate'   => array( '', '$$' ),
			'wordy-defaults-to-moderate'   => array( 'cheap', '$$' ),
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// sanitize_url_list — newline-separated URLs, drop invalid
	// ────────────────────────────────────────────────────────────────────────

	public function test_sanitize_url_list_keeps_valid_urls(): void {
		$input    = "https://facebook.com/foo\nhttps://twitter.com/bar";
		$expected = "https://facebook.com/foo\nhttps://twitter.com/bar";
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_url_list( $input ) );
	}

	public function test_sanitize_url_list_drops_invalid_lines(): void {
		$input    = "https://example.com/page\nnot-a-url\nhttps://valid.test\n   \nfoo bar baz";
		$expected = "https://example.com/page\nhttps://valid.test";
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_url_list( $input ) );
	}

	public function test_sanitize_url_list_handles_mixed_line_endings(): void {
		// Operators sometimes paste from Windows; \r\n must split same as \n.
		$input    = "https://a.test\r\nhttps://b.test\rhttps://c.test";
		$expected = "https://a.test\nhttps://b.test\nhttps://c.test";
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_url_list( $input ) );
	}

	public function test_sanitize_url_list_returns_empty_for_non_scalar(): void {
		$this->assertSame( '', Lafka_Customizer_Restaurant_Info::sanitize_url_list( array() ) );
		$this->assertSame( '', Lafka_Customizer_Restaurant_Info::sanitize_url_list( new \stdClass() ) );
	}

	// ────────────────────────────────────────────────────────────────────────
	// sanitize_hours — "HH:MM-HH:MM" or "closed" or empty
	// ────────────────────────────────────────────────────────────────────────

	/** @dataProvider hoursValidProvider */
	public function test_sanitize_hours_accepts_valid( $input, string $expected ): void {
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_hours( $input ) );
	}

	public function hoursValidProvider(): array {
		return array(
			'standard-day'        => array( '11:00-23:00', '11:00-23:00' ),
			'with-spaces-around-dash' => array( '11:00 - 23:00', '11:00-23:00' ),
			'closed-lowercase'    => array( 'closed', 'closed' ),
			'closed-uppercase'    => array( 'CLOSED', 'closed' ),
			'closed-mixed'        => array( 'Closed', 'closed' ),
			'empty-string'        => array( '', '' ),
			'whitespace-only'     => array( '   ', '' ),
			'midnight-open'       => array( '00:00-23:59', '00:00-23:59' ),
		);
	}

	/** @dataProvider hoursInvalidProvider */
	public function test_sanitize_hours_rejects_invalid( $input ): void {
		$this->assertSame( '', Lafka_Customizer_Restaurant_Info::sanitize_hours( $input ) );
	}

	public function hoursInvalidProvider(): array {
		return array(
			'no-dash'             => array( '11:00 23:00' ),
			'invalid-hour'        => array( '25:00-23:00' ),
			'invalid-minute'      => array( '11:60-23:00' ),
			'single-time'         => array( '11:00' ),
			'wrong-format'        => array( '11am-11pm' ),
			'array-input'         => array( array( '11:00-23:00' ) ),
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// sanitize_business_type — comma-separated schema.org PascalCase
	// ────────────────────────────────────────────────────────────────────────

	public function test_sanitize_business_type_keeps_valid_pascalcase(): void {
		$input    = 'Restaurant, LocalBusiness, FoodEstablishment';
		$expected = 'Restaurant, LocalBusiness, FoodEstablishment';
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_business_type( $input ) );
	}

	public function test_sanitize_business_type_strips_non_alphanumeric(): void {
		// Operator paste with hyphens / spaces: schema.org type names are
		// strict PascalCase ASCII so anything else is scrubbed character-wise.
		$input    = 'Cafe-Or-CoffeeShop, Bar Or Pub, Bakery!';
		$expected = 'CafeOrCoffeeShop, BarOrPub, Bakery';
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_business_type( $input ) );
	}

	public function test_sanitize_business_type_drops_empty_segments(): void {
		$input    = 'Restaurant,,LocalBusiness, ,FoodEstablishment';
		$expected = 'Restaurant, LocalBusiness, FoodEstablishment';
		$this->assertSame( $expected, Lafka_Customizer_Restaurant_Info::sanitize_business_type( $input ) );
	}

	public function test_sanitize_business_type_returns_empty_for_non_scalar(): void {
		$this->assertSame( '', Lafka_Customizer_Restaurant_Info::sanitize_business_type( array() ) );
	}
}
