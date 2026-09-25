<?php
/**
 * GX4 fulfilment preference (incl/checkout/class-lafka-fulfilment.php): one
 * pickup/delivery preference (cookie `lafka_order_method`, owned by the
 * plugin) preselects the matching WooCommerce shipping rate on the classic
 * and the block checkout alike — never over a rate the customer picked.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Fulfilment;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;
use WC_Cache_Helper;
use WC_Shipping_Zones;

require_once __DIR__ . '/Support/Hooks.php';
require_once __DIR__ . '/Stubs/wc-shipping-zones-stub.php';

final class FulfilmentPreferenceTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	public array $session = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<string, callable> */
	private array $filters = array();

	private bool $shipping_areas = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$_COOKIE          = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->options    = array();
		$this->session    = array();
		$this->transients = array();
		$this->filters    = array();
		$this->shipping_areas = false;
		WC_Shipping_Zones::$zones   = array();
		WC_Shipping_Zones::$calls   = 0;
		WC_Cache_Helper::$versions  = array();

		Functions\when( 'get_option' )->alias( fn( $key, $fallback = false ) => $this->options[ $key ] ?? $fallback );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_lafka_shipping_areas' )->alias( fn() => $this->shipping_areas );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
		);
		$session = new class( $this ) {
			public function __construct( private FulfilmentPreferenceTest $test ) {}
			public function get( $key, $fallback = null ) {
				return $this->test->session[ $key ] ?? $fallback;
			}
			public function set( $key, $value ) {
				$this->test->session[ $key ] = $value;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
		if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		}
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-fields.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-fulfilment.php';
		Lafka_Fulfilment::flush();
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Package rates keyed by rate id, as WooCommerce passes them. */
	private static function rates( string ...$ids ): array {
		$rates = array();
		foreach ( $ids as $id ) {
			$rates[ $id ] = new class( $id ) {
				public function __construct( private string $id ) {}
				public function get_method_id() {
					return strtok( $this->id, ':' );
				}
			};
		}
		return $rates;
	}

	/** WooCommerce bumps its "shipping" transient version on a zone change; next request. */
	private function shipping_changed(): void {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		Lafka_Fulfilment::flush();
	}

	private function both_modes(): void {
		WC_Shipping_Zones::$zones = array(
			1 => array( array( 'flat_rate', true ), array( 'local_pickup', true ) ),
		);
	}

	/* ------------------------------------------------------------ *
	 *  Modes
	 * ------------------------------------------------------------ */

	public function test_modes_come_from_the_enabled_shipping_methods(): void {
		WC_Shipping_Zones::$zones = array( 1 => array( array( 'local_pickup', true ), array( 'flat_rate', false ) ) );
		self::assertSame( array( 'pickup' ), lafka_fulfilment_modes() );

		$this->shipping_changed();
		WC_Shipping_Zones::$zones = array( 1 => array( array( 'distance_rate', true ) ), 0 => array( array( 'flat_rate', true ) ) );
		self::assertSame( array( 'delivery' ), lafka_fulfilment_modes() );

		$this->shipping_changed();
		WC_Shipping_Zones::$zones = array( 1 => array( array( 'flat_rate', true ) ), 0 => array( array( 'local_pickup', true ) ) );
		self::assertSame( array( 'pickup', 'delivery' ), lafka_fulfilment_modes() );
	}

	public function test_the_blocks_pickup_location_method_counts_as_pickup_once_a_location_is_on(): void {
		WC_Shipping_Zones::$zones = array( 1 => array( array( 'flat_rate', true ) ) );
		$this->options['woocommerce_pickup_location_settings'] = array( 'enabled' => 'yes' );
		$this->options['pickup_location_pickup_locations']     = array( array( 'name' => 'Counter', 'enabled' => false ) );
		self::assertSame( array( 'delivery' ), lafka_fulfilment_modes() );

		// update_option_pickup_location_pickup_locations busts the cache.
		Lafka_Fulfilment::bust_modes_cache();
		$this->options['pickup_location_pickup_locations'] = array( array( 'name' => 'Counter', 'enabled' => true ) );
		self::assertSame( array( 'pickup', 'delivery' ), lafka_fulfilment_modes() );
	}

	public function test_no_shipping_at_all_means_no_choice_to_offer(): void {
		$this->both_modes();
		$this->options['woocommerce_ship_to_countries'] = 'disabled';

		self::assertSame( array(), lafka_fulfilment_modes() );
	}

	public function test_the_zone_scan_is_cached_until_shipping_settings_change(): void {
		$this->both_modes();
		lafka_fulfilment_modes();
		Lafka_Fulfilment::flush();
		lafka_fulfilment_modes();
		self::assertSame( 1, WC_Shipping_Zones::$calls, 'The second request reads the transient.' );

		WC_Cache_Helper::get_transient_version( 'shipping', true );
		Lafka_Fulfilment::flush();
		WC_Shipping_Zones::$zones = array( 1 => array( array( 'local_pickup', true ) ) );
		self::assertSame( array( 'pickup' ), lafka_fulfilment_modes(), 'WooCommerce bumps the shipping version on every zone change.' );

		Lafka_Fulfilment::bust_modes_cache();
		Lafka_Fulfilment::flush();
		lafka_fulfilment_modes();
		self::assertSame( 3, WC_Shipping_Zones::$calls );
	}

	public function test_with_branch_selection_the_site_order_types_decide(): void {
		WC_Shipping_Zones::$zones = array( 1 => array( array( 'flat_rate', true ) ) );
		$this->shipping_areas     = true;
		$this->options['lafka_shipping_areas_branches'] = array(
			'enable_branch_selection_modal' => 1,
			'order_type'                    => 'pickup',
		);

		self::assertSame( array( 'pickup' ), lafka_fulfilment_modes() );
	}

	/* ------------------------------------------------------------ *
	 *  Preference
	 * ------------------------------------------------------------ */

	public function test_preference_is_the_cookie_when_it_names_an_offered_mode(): void {
		$this->both_modes();

		self::assertSame( '', lafka_fulfilment_preference() );

		$_COOKIE['lafka_order_method'] = 'pickup';
		self::assertSame( 'pickup', lafka_fulfilment_preference() );

		$_COOKIE['lafka_order_method'] = 'drone';
		self::assertSame( '', lafka_fulfilment_preference(), 'An unknown value is ignored.' );
	}

	public function test_a_mode_the_store_does_not_offer_is_ignored(): void {
		WC_Shipping_Zones::$zones      = array( 1 => array( array( 'local_pickup', true ) ) );
		$_COOKIE['lafka_order_method'] = 'delivery';

		self::assertSame( '', lafka_fulfilment_preference() );
	}

	public function test_a_validated_branch_session_order_type_wins_over_the_cookie(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method']         = 'delivery';
		$this->session['lafka_branch_location'] = array(
			'branch_id'  => 5,
			'order_type' => 'pickup',
		);

		self::assertSame( 'pickup', lafka_fulfilment_preference() );
	}

	/* ------------------------------------------------------------ *
	 *  Shipping rate preselection
	 * ------------------------------------------------------------ */

	public function test_pickup_preference_preselects_the_pickup_rate(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'pickup';

		self::assertSame( 'local_pickup:3', Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'local_pickup:3' ), false ) );
		self::assertSame( 'pickup_location:0', Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'pickup_location:0' ), false ) );
	}

	public function test_delivery_preference_preselects_the_first_delivery_rate(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'delivery';

		self::assertSame( 'distance_rate:8', Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3', 'distance_rate:8', 'flat_rate:1' ), false ) );
	}

	public function test_no_preference_keeps_the_woocommerce_default(): void {
		$this->both_modes();

		self::assertSame( 'flat_rate:1', Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'local_pickup:3' ), false ) );
	}

	public function test_a_rate_the_customer_chose_is_never_overridden(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'delivery';
		// The customer picked pickup at checkout; WooCommerce re-defaults when
		// the rate list changes and keeps a chosen pickup rate.
		$this->session['chosen_shipping_methods'] = array( 'local_pickup:3' );

		self::assertSame( 'local_pickup:3', Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3', 'flat_rate:1' ), 'local_pickup:3' ) );
	}

	public function test_delivery_waits_for_the_address_then_takes_over_an_automatic_pickup(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'delivery';

		// Delivery rates are withheld until there is a street address (GX0
		// quote guard): only pickup is on offer, so WooCommerce's default stays.
		self::assertSame( 'local_pickup:3', Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3' ), false ) );

		// The address arrives, delivery rates appear. The pickup rate was never
		// the customer's choice, so the preference now applies.
		self::assertSame( 'flat_rate:1', Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3', 'flat_rate:1' ), 'local_pickup:3' ) );
	}

	public function test_switching_back_to_an_earlier_automatic_rate_is_still_the_customers_choice(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'delivery';
		// Pickup first (no address yet), then delivery once the address is in.
		Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3' ), false );
		Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3', 'flat_rate:1' ), 'local_pickup:3' );

		// The customer now picks pickup at checkout; later the rates change
		// (they edit the address). Their pickup stays.
		self::assertSame( 'local_pickup:3', Lafka_Fulfilment::filter_chosen_method( 'local_pickup:3', self::rates( 'local_pickup:3', 'distance_rate:8' ), 'local_pickup:3' ) );
	}

	public function test_the_preselection_can_be_switched_off(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'pickup';
		$this->filters['lafka_fulfilment_preselect_enabled'] = static fn() => false;

		self::assertSame( 'flat_rate:1', Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'local_pickup:3' ), false ) );
		self::assertSame( 'pickup', lafka_fulfilment_preference(), 'The preference itself is still readable.' );
	}

	public function test_no_rates_is_left_to_woocommerce(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'pickup';

		self::assertFalse( Lafka_Fulfilment::filter_chosen_method( false, array(), false ) );
	}

	/* ------------------------------------------------------------ *
	 *  A changed preference re-applies to an automatic choice
	 * ------------------------------------------------------------ */

	public function test_a_changed_preference_releases_an_automatic_choice_on_the_next_page_load(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'pickup';
		Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'local_pickup:3' ), false );
		$this->session['chosen_shipping_methods'] = array( 'local_pickup:3' );

		$_COOKIE['lafka_order_method'] = 'delivery';
		Lafka_Fulfilment::maybe_release_automatic_choice();

		self::assertSame( array(), $this->session['chosen_shipping_methods'], 'WooCommerce re-defaults through the filter.' );
	}

	public function test_an_explicit_choice_is_never_released(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'pickup';
		Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'local_pickup:3' ), false );
		$this->session['chosen_shipping_methods'] = array( 'distance_rate:8' );

		$_COOKIE['lafka_order_method'] = 'delivery';
		Lafka_Fulfilment::maybe_release_automatic_choice();

		self::assertSame( array( 'distance_rate:8' ), $this->session['chosen_shipping_methods'] );
	}

	public function test_nothing_is_released_while_an_order_is_being_submitted(): void {
		$this->both_modes();
		$_COOKIE['lafka_order_method'] = 'pickup';
		Lafka_Fulfilment::filter_chosen_method( 'flat_rate:1', self::rates( 'flat_rate:1', 'local_pickup:3' ), false );
		$this->session['chosen_shipping_methods'] = array( 'local_pickup:3' );
		$_COOKIE['lafka_order_method']            = 'delivery';

		$_SERVER['REQUEST_METHOD'] = 'POST';
		Lafka_Fulfilment::maybe_release_automatic_choice();
		self::assertSame( array( 'local_pickup:3' ), $this->session['chosen_shipping_methods'] );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Lafka_Fulfilment::maybe_release_automatic_choice();
		self::assertSame( array( 'local_pickup:3' ), $this->session['chosen_shipping_methods'] );
	}

	/* ------------------------------------------------------------ *
	 *  Wiring
	 * ------------------------------------------------------------ */

	public function test_hooks_the_default_rate_and_the_cache_busting(): void {
		Hooks::reset();
		Lafka_Fulfilment::init();
		$registered = Hooks::registered();

		self::assertContains( 'woocommerce_shipping_chosen_method -> filter_chosen_method', $registered );
		self::assertContains( 'woocommerce_cart_loaded_from_session -> maybe_release_automatic_choice', $registered );
		self::assertContains( 'woocommerce_shipping_zone_method_status_toggled -> bust_modes_cache', $registered );
		self::assertContains( 'woocommerce_shipping_zone_method_added -> bust_modes_cache', $registered );
		self::assertContains( 'woocommerce_shipping_zone_method_deleted -> bust_modes_cache', $registered );
	}
}
