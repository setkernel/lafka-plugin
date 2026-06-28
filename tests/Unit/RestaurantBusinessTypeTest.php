<?php
/**
 * Regression: operator-selected schema.org business type must flow through to
 * lafka_get_restaurant_info()['business_type'] and the Restaurant JSON-LD @type.
 *
 * Before this fix, `business_type` was a hardcoded literal
 * (['Restaurant','LocalBusiness','FoodEstablishment']) and the value collected
 * by BOTH the Customizer "Restaurant Information" panel and the WooCommerce
 * "Restaurant" settings tab (stored under lafka_business_business_type) was
 * silently discarded. A bakery/cafe could never correct its @type.
 *
 * The resolver now parses the stored CSV via lafka_schema_normalize_csv_list()
 * and falls back to the Restaurant default only when nothing is stored.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.x
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php';

final class RestaurantBusinessTypeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Wire common WP stubs. $options is a map consulted by get_option();
	 * $theme_mods is consulted by get_theme_mod(). Both fall through to the
	 * caller-supplied default when a key is absent.
	 *
	 * @param array<string, mixed> $options    wp_options map.
	 * @param array<string, mixed> $theme_mods theme_mod map.
	 */
	private function stub_wp( array $options = array(), array $theme_mods = array() ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
			}
		);
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $theme_mods ) {
				return array_key_exists( $key, $theme_mods ) ? $theme_mods[ $key ] : $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
	}

	/**
	 * Operator value saved to wp_options (the canonical WC Settings write
	 * surface) is parsed from CSV into an ordered string[].
	 */
	public function test_business_type_resolves_csv_from_option(): void {
		$this->stub_wp( array( 'lafka_business_business_type' => 'CafeOrCoffeeShop, FoodEstablishment' ) );

		$info = lafka_get_restaurant_info();

		self::assertSame(
			array( 'CafeOrCoffeeShop', 'FoodEstablishment' ),
			$info['business_type']
		);
	}

	/**
	 * Legacy Customizer value (theme_mod) is honored as fallback when no
	 * wp_options value is stored.
	 */
	public function test_business_type_resolves_from_theme_mod(): void {
		$this->stub_wp(
			array(),
			array( 'lafka_business_business_type' => 'Bakery' )
		);

		$info = lafka_get_restaurant_info();

		self::assertSame( array( 'Bakery' ), $info['business_type'] );
	}

	/**
	 * With nothing stored on either write surface, the Restaurant default is
	 * preserved (OSS-shipped behavior unchanged).
	 */
	public function test_business_type_falls_back_to_default_when_unset(): void {
		$this->stub_wp();

		$info = lafka_get_restaurant_info();

		self::assertSame(
			array( 'Restaurant', 'LocalBusiness', 'FoodEstablishment' ),
			$info['business_type']
		);
	}

	/**
	 * End-to-end: the emitted Restaurant JSON-LD @type reflects the operator's
	 * choice — a cafe is no longer forced to advertise itself as a Restaurant.
	 */
	public function test_restaurant_schema_type_reflects_operator_business_type(): void {
		$this->stub_wp( array( 'lafka_business_business_type' => 'CafeOrCoffeeShop' ) );

		$schema = lafka_schema_restaurant();

		self::assertIsArray( $schema );
		self::assertSame( array( 'CafeOrCoffeeShop' ), array_values( (array) $schema['@type'] ) );
		self::assertNotContains( 'Restaurant', (array) $schema['@type'] );
	}
}
