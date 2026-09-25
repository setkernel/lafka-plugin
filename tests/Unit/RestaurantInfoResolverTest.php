<?php
/**
 * RestaurantInfoResolverTest — exercises the four-layer resolution chain in
 * `lafka_get_restaurant_info()`:
 *
 *   1. Customizer theme_mod  (`lafka_business_<key>`)
 *   2. Programmatic option   (`lafka_business_<key>`)
 *   3. WooCommerce store option (`woocommerce_store_*` / `woocommerce_default_country`)
 *   4. Sensible default      (or empty for fields that are skipped from schema)
 *
 * plus the WP-core fallbacks and the lafka_restaurant_info filter.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class RestaurantInfoResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// theme_mod / lafka_business_* option layer empty by default — tests
		// that exercise specific layers stub them per-test.
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a get_option stub that returns values from a fixture map and ''
	 * for everything else. Centralises the boilerplate so each test can
	 * declare its WC store options as a one-liner.
	 *
	 * @param array<string, string> $options
	 */
	private function stub_wc_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = null ) use ( $options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : ( null === $default ? '' : $default );
			}
		);
	}

	public function test_address_inherits_from_wc_store_options_when_customizer_blank(): void {
		$this->stub_wc_options(
			array(
				'woocommerce_store_address'    => '742 Evergreen Terrace',
				'woocommerce_store_city'       => 'Springfield',
				'woocommerce_store_postcode'   => '49007',
				'woocommerce_default_country'  => 'US:IL',
			)
		);

		$info = \lafka_get_restaurant_info();

		$this->assertSame( '742 Evergreen Terrace', $info['street'], 'street should fall back to woocommerce_store_address' );
		$this->assertSame( 'Springfield', $info['city'], 'city should fall back to woocommerce_store_city' );
		$this->assertSame( '49007', $info['postal'], 'postal should fall back to woocommerce_store_postcode' );
		$this->assertSame( 'US', $info['country'], 'country should be the prefix of woocommerce_default_country (CC:RR)' );
		$this->assertSame( 'IL', $info['region'], 'region should be the suffix of woocommerce_default_country (CC:RR)' );
	}

	public function test_country_works_without_state_suffix(): void {
		$this->stub_wc_options( array( 'woocommerce_default_country' => 'CA' ) );
		$info = \lafka_get_restaurant_info();
		$this->assertSame( 'CA', $info['country'] );
		$this->assertSame( '', $info['region'], 'region must be blank when WC option lacks the :STATE suffix' );
	}

	public function test_phone_inherits_from_wc_store_phone(): void {
		$this->stub_wc_options( array( 'woocommerce_store_phone' => '+15551234567' ) );
		$info = \lafka_get_restaurant_info();
		$this->assertSame( '+15551234567', $info['phone_e164'] );
		$this->assertSame( '(555) 123-4567', $info['phone_display'], 'phone_display falls back to the national format of phone_e164' );
	}

	public function test_a_display_phone_stored_as_bare_e164_is_shown_in_national_format(): void {
		$this->stub_wc_options(
			array(
				'lafka_business_phone_e164'    => '+15551234567',
				'lafka_business_phone_display' => '+15551234567',
				'woocommerce_default_country'  => 'CA:NS',
			)
		);

		$info = \lafka_get_restaurant_info();

		$this->assertSame( '(555) 123-4567', $info['phone_display'] );
		$this->assertSame( '+15551234567', $info['phone_e164'], 'tel: links keep E.164.' );
	}

	public function test_an_operator_formatted_display_phone_is_kept_verbatim(): void {
		$this->stub_wc_options(
			array(
				'lafka_business_phone_e164'    => '+15551234567',
				'lafka_business_phone_display' => '555-123-4567 ext. 2',
			)
		);

		$this->assertSame( '555-123-4567 ext. 2', \lafka_get_restaurant_info()['phone_display'] );
	}

	public function test_customizer_value_overrides_wc_store_value(): void {
		// Multi-location use case: WC checkout/email branding stays at HQ
		// address; schema/JSON-LD uses the per-location Customizer value.
		$this->stub_wc_options( array( 'woocommerce_store_address' => 'WC HQ Address' ) );
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_business_street' === $key ? 'Schema Override Street' : $default;
			}
		);

		$info = \lafka_get_restaurant_info();

		$this->assertSame( 'Schema Override Street', $info['street'], 'theme_mod must take precedence over WC store option' );
	}

	public function test_lafka_business_option_overrides_wc_store_value(): void {
		// Programmatic / migration use case: an option set via update_option
		// (no Customizer save) should still trump the WC fallback.
		$this->stub_wc_options(
			array(
				'woocommerce_store_address' => 'WC Address',
				'lafka_business_street'     => 'Migrated Address',
			)
		);

		$info = \lafka_get_restaurant_info();

		$this->assertSame( 'Migrated Address', $info['street'] );
	}

	public function test_resolver_falls_through_to_default_when_all_layers_empty(): void {
		// No theme_mod, no lafka_business_* option, no WC store option —
		// resolver returns '' for fields with empty default.
		$this->stub_wc_options( array() );

		$info = \lafka_get_restaurant_info();

		$this->assertSame( '', $info['street'] );
		$this->assertSame( '', $info['city'] );
		$this->assertSame( '', $info['country'] );
		$this->assertSame( '', $info['phone_e164'] );
	}

	public function test_address_display_composite_uses_wc_fallback_values(): void {
		// The resolver synthesises an `address_display` (multi-line) and an
		// `address_short` ("street, city") from the resolved fields. Sanity-
		// check that the WC fallback flows all the way through to the
		// composite outputs that templates read.
		$this->stub_wc_options(
			array(
				'woocommerce_store_address'   => '123 Main',
				'woocommerce_store_city'      => 'Smalltown',
				'woocommerce_store_postcode'  => 'A1B 2C3',
				'woocommerce_default_country' => 'CA:ON',
			)
		);

		$info = \lafka_get_restaurant_info();

		$this->assertStringContainsString( '123 Main', $info['address_display'] );
		$this->assertStringContainsString( 'Smalltown', $info['address_display'] );
		$this->assertStringContainsString( 'ON A1B 2C3', $info['address_display'] );
		$this->assertSame( '123 Main, Smalltown', $info['address_short'] );
	}

	public function test_customizer_values_feed_the_composites_and_hours(): void {
		$this->stub_wc_options( array() );
		$mods = array(
			'lafka_business_name'       => 'Test Cafe',
			'lafka_business_street'     => '123 Main St',
			'lafka_business_city'       => 'Springfield',
			'lafka_business_region'     => 'IL',
			'lafka_business_postal'     => '62704',
			'lafka_business_country'    => 'US',
			'lafka_business_phone_e164' => '+15551234567',
			'lafka_business_geo_lat'    => '39.78',
			'lafka_business_hours_mon'  => '11:00-23:00',
		);
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = null ) => $mods[ $key ] ?? $default );

		$info = \lafka_get_restaurant_info();

		$this->assertSame( 'Test Cafe', $info['name'] );
		$this->assertSame( '+15551234567', $info['phone_e164'] );
		$this->assertSame( '39.78', $info['geo_lat'] );
		$this->assertSame( '123 Main St, Springfield', $info['address_short'] );
		$this->assertStringContainsString( 'IL 62704', $info['address_display'] );
		$this->assertNotSame( '', $info['directions_url'] );
		$this->assertSame( array( 'Monday' => '11:00-23:00' ), $info['hours'] );
		$this->assertCount( 1, $info['opening_hours'] );
	}

	public function test_name_and_email_fall_back_to_wp_core(): void {
		$this->stub_wc_options( array() );
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $what ) => array( 'name' => 'Generic WP Site', 'admin_email' => 'admin@example.test' )[ $what ] ?? ''
		);

		$info = \lafka_get_restaurant_info();

		$this->assertSame( 'Generic WP Site', $info['name'] );
		$this->assertSame( 'admin@example.test', $info['email'] );
	}

	public function test_lafka_restaurant_info_filter_has_the_last_word(): void {
		$this->stub_wc_options( array() );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'lafka_restaurant_info' === $hook && is_array( $value ) ) {
					$value['name'] = 'Filter Override';
				}
				return $value;
			}
		);

		$this->assertSame( 'Filter Override', \lafka_get_restaurant_info()['name'] );
	}
}
