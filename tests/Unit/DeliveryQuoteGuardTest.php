<?php
/**
 * Delivery quote guard (GX0): a delivery rate is never quoted from a partial
 * address. Until the destination carries a street address and a postcode,
 * every rate that needs an address (default: every non-pickup rate) is
 * withheld and the customer is told why; pickup rates are untouched.
 *
 * Live evidence: a distance-rate plugin quoted "$52.20" from the province
 * centroid before any street address was entered.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Delivery_Quote_Guard;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';

final class DeliveryQuoteGuardTest extends TestCase {

	private const MESSAGE = 'Enter your street address to see the delivery cost.';

	/** @var array<string, mixed> */
	private array $theme_mods = array();

	/** @var array<string, mixed> WC session contents. */
	private array $session = array();

	/** @var array<string, array<string, mixed>> Country address locales. */
	private array $locale = array();

	/** @var array<string, callable> Filters a test overrides, by hook. */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $fallback = false ) => $this->theme_mods[ $key ] ?? $fallback );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				return isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value;
			}
		);
		$session = new class( $this ) {
			public function __construct( private DeliveryQuoteGuardTest $test ) {}
			public function get( $key ) {
				return $this->test->session_get( $key );
			}
			public function set( $key, $value ) {
				$this->test->session_set( $key, $value );
			}
		};
		$countries = new class( $this ) {
			public function __construct( private DeliveryQuoteGuardTest $test ) {}
			public function get_country_locale() {
				return $this->test->locale();
			}
		};
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'session'   => $session,
				'countries' => $countries,
			)
		);
		require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-delivery-quote-guard.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return mixed */
	public function session_get( string $key ) {
		return $this->session[ $key ] ?? null;
	}

	/** @param mixed $value */
	public function session_set( string $key, $value ): void {
		$this->session[ $key ] = $value;
	}

	/** @return array<string, array<string, mixed>> */
	public function locale(): array {
		return $this->locale;
	}

	private static function rate( string $method_id ): object {
		return (object) array(
			'method_id' => $method_id,
			'cost'      => 5.0,
		);
	}

	/** @return array<string, object> */
	private static function rates(): array {
		return array(
			'local_pickup:9'    => self::rate( 'local_pickup' ),
			'pickup_location:0' => self::rate( 'pickup_location' ),
			'distance_rate:8'   => self::rate( 'distance_rate' ),
			'flat_rate:2'       => self::rate( 'flat_rate' ),
		);
	}

	/**
	 * @param array<string, string> $destination
	 * @return string[] Rate ids the guard keeps.
	 */
	private function quote( array $destination ): array {
		return array_keys( Lafka_Delivery_Quote_Guard::filter_package_rates( self::rates(), array( 'destination' => $destination ) ) );
	}

	private function render_row(): string {
		ob_start();
		Lafka_Delivery_Quote_Guard::render_notice_row();
		return (string) ob_get_clean();
	}

	public function test_a_province_only_destination_quotes_pickup_but_no_delivery(): void {
		$kept = $this->quote(
			array(
				'country' => 'CA',
				'state'   => 'NS',
			)
		);

		$this->assertSame( array( 'local_pickup:9', 'pickup_location:0' ), $kept );
		$this->assertStringContainsString( self::MESSAGE, $this->render_row(), 'The customer is told why delivery is missing.' );
	}

	public function test_a_street_address_without_a_postcode_is_still_partial(): void {
		$kept = $this->quote(
			array(
				'country'   => 'CA',
				'state'     => 'NS',
				'address_1' => '1 Example Street',
			)
		);

		$this->assertSame( array( 'local_pickup:9', 'pickup_location:0' ), $kept );
	}

	/**
	 * The gap WooCommerce's own "Hide shipping costs until an address is
	 * entered" leaves: its classic check (WC_Cart::show_shipping) is satisfied
	 * by country + state + postcode — no street — and it is skipped entirely
	 * whenever the blocks Local Pickup method is enabled. The live store had
	 * that option ON and still quoted delivery from the province.
	 */
	public function test_a_destination_core_accepts_without_a_street_is_still_withheld(): void {
		$kept = $this->quote(
			array(
				'country'  => 'CA',
				'state'    => 'NS',
				'postcode' => 'A1A 1A1',
				'city'     => 'Exampleville',
			)
		);

		$this->assertSame( array( 'local_pickup:9', 'pickup_location:0' ), $kept );
	}

	public function test_a_full_address_quotes_every_rate_and_clears_the_notice(): void {
		$this->quote( array( 'country' => 'CA' ) );

		$kept = $this->quote(
			array(
				'country'  => 'CA',
				'state'    => 'NS',
				'postcode' => 'A1A 1A1',
				'address'  => '1 Example Street', // WC also mirrors address_1 as `address`.
			)
		);

		$this->assertSame( array_keys( self::rates() ), $kept );
		$this->assertSame( '', $this->render_row() );
	}

	public function test_a_country_without_postcodes_needs_only_the_street(): void {
		$this->locale = array( 'HK' => array( 'postcode' => array( 'required' => false ) ) );

		$kept = $this->quote(
			array(
				'country'   => 'HK',
				'address_1' => '1 Example Road',
			)
		);

		$this->assertSame( array_keys( self::rates() ), $kept );
	}

	public function test_the_operator_can_switch_the_guard_off(): void {
		$this->theme_mods['lafka_delivery_quote_guard'] = false;

		$this->assertSame( array_keys( self::rates() ), $this->quote( array( 'country' => 'CA' ) ) );
		$this->assertSame( '', $this->render_row() );
	}

	public function test_a_method_that_needs_no_address_can_be_opted_out(): void {
		$this->filters['lafka_delivery_rate_needs_address'] = static fn( $needs, $rate ) => 'flat_rate' === $rate->method_id ? false : $needs;

		$this->assertSame(
			array( 'local_pickup:9', 'pickup_location:0', 'flat_rate:2' ),
			$this->quote( array( 'country' => 'CA' ) )
		);
	}

	public function test_real_wc_rate_objects_are_read_through_their_getter(): void {
		$rate = new class() {
			public function get_method_id(): string {
				return 'local_pickup';
			}
		};

		$this->assertFalse( Lafka_Delivery_Quote_Guard::rate_needs_address( $rate ) );
		$this->assertTrue( Lafka_Delivery_Quote_Guard::rate_needs_address( self::rate( 'distance_rate' ) ) );
	}

	public function test_the_message_is_operator_configurable(): void {
		$this->theme_mods['lafka_delivery_quote_guard_message'] = 'Add your street to price delivery.';
		$this->quote( array( 'country' => 'CA' ) );

		$this->assertStringContainsString( 'Add your street to price delivery.', $this->render_row() );
	}

	public function test_when_only_delivery_exists_the_empty_rates_message_explains_why(): void {
		$generic = 'There are no shipping options available.';

		$this->assertSame( $generic, Lafka_Delivery_Quote_Guard::filter_no_shipping_html( $generic ), 'Nothing withheld: WooCommerce copy stands.' );

		$kept = Lafka_Delivery_Quote_Guard::filter_package_rates(
			array( 'distance_rate:8' => self::rate( 'distance_rate' ) ),
			array( 'destination' => array( 'country' => 'CA' ) )
		);

		$this->assertSame( array(), $kept );
		$this->assertSame( self::MESSAGE, Lafka_Delivery_Quote_Guard::filter_no_shipping_html( $generic ) );
		$this->assertSame( '', $this->render_row(), 'The message is not printed twice.' );
	}

	public function test_hooks_cover_rates_and_both_classic_totals_tables(): void {
		Hooks::reset();

		Lafka_Delivery_Quote_Guard::init();

		$this->assertSame(
			array(
				'woocommerce_package_rates -> filter_package_rates',
				'woocommerce_cart_totals_after_shipping -> render_notice_row',
				'woocommerce_review_order_after_shipping -> render_notice_row',
				'woocommerce_no_shipping_available_html -> filter_no_shipping_html',
				'woocommerce_cart_no_shipping_available_html -> filter_no_shipping_html',
			),
			Hooks::registered()
		);
	}
}
