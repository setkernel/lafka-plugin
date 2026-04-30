<?php
/**
 * ContactsWidgetNapInheritanceTest — locks down v9.7.22 NAP-inheritance
 * behaviour added to LafkaContactsWidget.
 *
 * Mirrors the customizer fix from v9.7.6: the widget defaults each NAP
 * field to the canonical lafka_get_restaurant_info() resolver (which
 * itself flows from WC settings → Lafka customizer → empty), and only
 * surfaces an operator-supplied value when the field is non-blank.
 *
 * Patchwork can't redefine lafka_get_restaurant_info() once another test
 * has loaded it (which JsonLdSchemaTest does), so we stub its UNDERLYING
 * inputs (get_theme_mod / get_option) and let the real resolver compute.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.22
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Stubs/wp-widget-stub.php';
require_once dirname( __DIR__, 2 ) . '/widgets/LafkaContactsWidget.php';

final class ContactsWidgetNapInheritanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Common WP function stubs that lafka_get_restaurant_info() touches.
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_option' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_resolve( array $instance, string $field ): string {
		$reflection = new ReflectionClass( \LafkaContactsWidget::class );
		$widget     = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'resolve' );

		return (string) $method->invoke( $widget, $instance, $field );
	}

	/**
	 * Helper: stub get_option to return values from a fixture map (the
	 * resolver reads woocommerce_store_*) so each test can inject the
	 * canonical NAP via WC store-options without booting WC itself.
	 *
	 * @param array<string, string> $options
	 */
	private function stub_wc_store_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = null ) use ( $options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : ( null === $default ? '' : $default );
			}
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// Override-wins paths (don't need the resolver to compute anything)
	// ────────────────────────────────────────────────────────────────────────

	public function test_override_wins_for_address(): void {
		$this->assertSame(
			'Widget Override Address',
			$this->call_resolve( array( 'address' => 'Widget Override Address' ), 'address' )
		);
	}

	public function test_override_wins_for_phone(): void {
		$this->assertSame(
			'+1 555-9999',
			$this->call_resolve( array( 'phone' => '+1 555-9999' ), 'phone' )
		);
	}

	public function test_override_wins_for_email(): void {
		$this->assertSame(
			'override@example.test',
			$this->call_resolve( array( 'email' => 'override@example.test' ), 'email' )
		);
	}

	public function test_whitespace_only_override_treated_as_blank(): void {
		// Operator typing '   ' shouldn't suppress the canonical fallback.
		// With no inputs configured the resolver returns '' so we just
		// assert the override didn't take effect.
		$result = $this->call_resolve( array( 'address' => '   ' ), 'address' );
		$this->assertSame( '', $result, 'Whitespace-only override should not block fallback.' );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Inheritance paths (run real resolver under stubbed WC options)
	// ────────────────────────────────────────────────────────────────────────

	public function test_address_inherits_from_wc_store_options(): void {
		$this->stub_wc_store_options(
			array(
				'woocommerce_store_address'   => '742 Evergreen Terrace',
				'woocommerce_store_city'      => 'Springfield',
				'woocommerce_store_postcode'  => '49007',
				'woocommerce_default_country' => 'US:IL',
			)
		);

		$result = $this->call_resolve( array(), 'address' );

		// Resolver computes address_short = "{street}, {city}".
		$this->assertSame( '742 Evergreen Terrace, Springfield', $result );
	}

	public function test_phone_inherits_e164_from_wc_store_phone(): void {
		$this->stub_wc_store_options( array( 'woocommerce_store_phone' => '+15550100' ) );

		// Resolver fills phone_display from phone_e164 when no separate
		// display is set. Widget reads phone_display first, then phone_e164.
		$this->assertSame( '+15550100', $this->call_resolve( array(), 'phone' ) );
	}

	public function test_worktime_no_canonical_fallback(): void {
		// `hours` map in the resolver is per-day display strings — the widget
		// expects a single summary line that the resolver doesn't produce.
		// Operator must fill the override field; no surprising auto-fill.
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_business_hours_mon' === $key ? '11:00-23:00' : $default;
			}
		);

		$this->assertSame( '', $this->call_resolve( array(), 'worktime' ) );
	}

	public function test_fax_no_canonical_fallback(): void {
		// Resolver doesn't carry a fax field — operator must override.
		$this->assertSame( '', $this->call_resolve( array(), 'fax' ) );
	}
}
