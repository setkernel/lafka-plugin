<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * P6-UX-5 + P6-SEO-1 + W2-T1 regression lock: canonical NAP must be
 * Customizer-driven, NOT hardcoded. After the W2-T1 refactor:
 *
 *   - lafka_get_restaurant_info() (lafka-schema-helpers.php) is the single
 *     resolver. It reads theme_mod -> option -> WP-core fallback per field.
 *   - lafka_schema_get_nap() pulls from the resolver.
 *   - The [lafka_nap] shortcode in lafka-plugin.php delegates to
 *     lafka_schema_get_nap().
 *
 * No restaurant-specific literals must appear in OSS source. Tests assert
 * STRUCTURE (presence + types) and resolver behavior (Customizer override),
 * not literal Peppery values.
 */
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class NapShortcodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The schema helpers file MUST NOT contain restaurant-specific literals.
	 * Operator content flows through Customizer, not source code.
	 */
	public function test_helpers_file_contains_no_hardcoded_site_values(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php' );
		$forbidden = array( 'Peppery', 'Sackville Drive', 'B4C 2R8', '19022525353', '902-252-5353', '44.7720', '-63.6789', 'three.ppps' );
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$src,
				"OSS-safety: {$needle} must not appear in lafka-schema-helpers.php — Customizer is the source-of-truth."
			);
		}
	}

	/**
	 * Resolver returns the documented array shape with all required keys.
	 */
	public function test_resolver_returns_documented_shape(): void {
		Functions\when( 'get_theme_mod' )->returnArg( 2 ); // returns the default
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://example.test' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$info = lafka_get_restaurant_info();

		$expected_keys = array(
			'name', 'street', 'city', 'region', 'postal', 'country',
			'address_display', 'address_short',
			'phone_e164', 'phone_display', 'email',
			'geo_lat', 'geo_lng',
			'price_range', 'cuisines', 'payment_methods',
			'business_type', 'same_as',
			'logo_url', 'menu_url', 'directions_url',
			'hours', 'opening_hours',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $info, "Resolver must expose '{$key}'" );
		}
		$this->assertIsArray( $info['business_type'] );
		$this->assertIsArray( $info['cuisines'] );
		$this->assertIsArray( $info['payment_methods'] );
		$this->assertIsArray( $info['same_as'] );
		$this->assertIsArray( $info['hours'] );
		$this->assertIsArray( $info['opening_hours'] );
	}

	/**
	 * Resolver picks up theme_mod values when set (Customizer override path).
	 */
	public function test_resolver_picks_up_theme_mod_values(): void {
		$fixtures = array(
			'lafka_business_name'          => 'Test Cafe',
			'lafka_business_street'        => '123 Main St',
			'lafka_business_city'          => 'Springfield',
			'lafka_business_region'        => 'IL',
			'lafka_business_postal'        => '62704',
			'lafka_business_country'       => 'US',
			'lafka_business_phone_e164'    => '+15551234567',
			'lafka_business_phone_display' => '+1 555-123-4567',
			'lafka_business_geo_lat'       => '39.78',
			'lafka_business_geo_lng'       => '-89.65',
			'lafka_business_hours_mon'     => '11:00-23:00',
		);
		Functions\when( 'get_theme_mod' )->alias( function ( $key, $default = null ) use ( $fixtures ) {
			return $fixtures[ $key ] ?? $default;
		} );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://example.test' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$info = lafka_get_restaurant_info();

		$this->assertSame( 'Test Cafe', $info['name'] );
		$this->assertSame( '123 Main St', $info['street'] );
		$this->assertSame( 'Springfield', $info['city'] );
		$this->assertSame( '+15551234567', $info['phone_e164'] );
		$this->assertSame( '39.78', $info['geo_lat'] );
		$this->assertNotEmpty( $info['address_display'] );
		$this->assertNotEmpty( $info['address_short'] );
		$this->assertNotEmpty( $info['directions_url'] );
		$this->assertArrayHasKey( 'Monday', $info['hours'] );
		$this->assertSame( '11:00-23:00', $info['hours']['Monday'] );
		$this->assertCount( 1, $info['opening_hours'] );
	}

	/**
	 * Resolver falls back to WP core (get_bloginfo) when no Customizer value.
	 */
	public function test_resolver_falls_back_to_wp_core(): void {
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->alias( function ( $what ) {
			if ( 'name' === $what ) {
				return 'Generic WP Site';
			}
			if ( 'admin_email' === $what ) {
				return 'admin@example.test';
			}
			return '';
		} );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://example.test' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$info = lafka_get_restaurant_info();

		$this->assertSame( 'Generic WP Site', $info['name'] );
		$this->assertSame( 'admin@example.test', $info['email'] );
	}

	/**
	 * The lafka_restaurant_info filter is the topmost extension point.
	 */
	public function test_resolver_filterable_via_lafka_restaurant_info(): void {
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://example.test' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
			if ( 'lafka_restaurant_info' === $hook && is_array( $value ) ) {
				$value['name'] = 'Filter Override';
			}
			return $value;
		} );

		$info = lafka_get_restaurant_info();
		$this->assertSame( 'Filter Override', $info['name'] );
	}

	/**
	 * The shortcode must delegate to lafka_schema_get_nap() — no inline literals.
	 */
	public function test_shortcode_delegates_to_nap_helper(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString(
			'lafka_schema_get_nap()',
			$src,
			'lafka_nap_shortcode must call lafka_schema_get_nap() (not duplicate inline literals)'
		);
	}

	public function test_shortcode_registered(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression(
			"/add_shortcode\(\s*['\"]lafka_nap['\"]\s*,\s*['\"]lafka_nap_shortcode['\"]/",
			$src
		);
		$this->assertStringContainsString( 'function lafka_nap_shortcode', $src );
	}

	public function test_part_attribute_supported(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		// Must support part-based switching (verify via switch case keywords)
		foreach ( array( 'name', 'address', 'street', 'city', 'region', 'postal', 'phone' ) as $part ) {
			$this->assertMatchesRegularExpression(
				"/case\s+['\"]{$part}['\"]/",
				$src,
				"[lafka_nap part='{$part}'] is not handled"
			);
		}
	}
}
