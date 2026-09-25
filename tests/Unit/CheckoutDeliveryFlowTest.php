<?php
/**
 * Regression: the classic checkout "pickup → delivery" flow, end to end on
 * the server side, as the post-merge click-test ran it (gx0-click.js).
 *
 * The rate pipeline is WooCommerce's woocommerce_package_rates chain in its
 * real order: the delivery rate (priority 10), Promotions' delivery minimum
 * (10), the quote guard (50); then the fulfilment preference picks the rate.
 *
 *  - A $25.99 cart under a $30 delivery minimum can never be delivered: the
 *    minimum removes the delivery rate whatever the address, so the guard adds
 *    no "Delivery" placeholder, the order stays pickup, and the pickup form
 *    must not offer "Want delivery? Add your address" (it did — that is what
 *    looked like a broken pickup→delivery switch).
 *  - Over the minimum: no address → pickup + the "Delivery" placeholder; the
 *    address arrives → the real delivery rate replaces it and is chosen.
 *  - The street the customer typed survives a map plugin that blanks it.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Delivery_Quote_Guard;
use Lafka_Order_Path;
use Lafka_Pickup_Checkout;
use Lafka_Promotions;
use PHPUnit\Framework\TestCase;

final class CheckoutDeliveryFlowTest extends TestCase {

	/** @var array<string, mixed> */
	private array $session = array();

	private float $contents = 25.99;

	/** @var array<string, callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->session  = array();
		$this->contents = 25.99;
		$this->filters  = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'is_lafka_promotions' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) {
				if ( 'lafka_promotions_options' === $key ) {
					return array( 'delivery_min' => 30 );
				}
				if ( 'lafka_checkout_mode' === $key ) {
					return 'classic';
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
		);
		$session = new class( $this ) {
			public function __construct( private CheckoutDeliveryFlowTest $test ) {}
			public function get( $key ) {
				return $this->test->session_get( $key );
			}
			public function set( $key, $value ) {
				$this->test->session_set( $key, $value );
			}
		};
		$cart    = new class( $this ) {
			public function __construct( private CheckoutDeliveryFlowTest $test ) {}
			public function get_cart_contents_total() {
				return $this->test->contents();
			}
		};
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'session'   => $session,
				'cart'      => $cart,
				'countries' => null,
			)
		);
		require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-mode.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-delivery-quote-guard.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-pickup-checkout.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-order-path.php';
		require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';
		require_once __DIR__ . '/Stubs/wc-shipping-rate-stub.php';
		Lafka_Promotions::flush_knobs();
		unset( $_SERVER['REQUEST_URI'] );
	}

	protected function tearDown(): void {
		Lafka_Promotions::flush_knobs();
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

	public function contents(): float {
		return $this->contents;
	}

	/**
	 * The woocommerce_package_rates chain for a destination.
	 *
	 * @param array<string, string> $destination Package destination.
	 * @return string[] Rate ids offered.
	 */
	private function rates_for( array $destination ): array {
		$rates = array(
			'local_pickup:9'  => new \WC_Shipping_Rate( 'local_pickup:9', 'Pickup from Store', 0, array(), 'local_pickup', 9 ),
			'distance_rate:8' => new \WC_Shipping_Rate( 'distance_rate:8', 'Delivery', 5.5, array(), 'distance_rate', 8 ),
		);
		$package = array(
			'contents_cost' => $this->contents,
			'destination'   => $destination,
		);
		$promotions = ( new \ReflectionClass( Lafka_Promotions::class ) )->newInstanceWithoutConstructor();
		$rates      = $promotions->apply_delivery_minimum( $rates, $package );

		return array_keys( Lafka_Delivery_Quote_Guard::filter_package_rates( $rates, $package ) );
	}

	private const ADDRESS = array(
		'country'   => 'CA',
		'state'     => 'NS',
		'address_1' => '100 Example Rd',
		'city'      => 'Exampletown',
		'postcode'  => 'A1A 1A1',
	);

	public function test_under_the_delivery_minimum_the_order_can_only_be_pickup_and_says_so(): void {
		// Before and after the address: pickup only, no "Delivery" placeholder.
		$this->assertSame( array( 'local_pickup:9' ), $this->rates_for( array( 'country' => 'CA' ) ) );
		$this->assertSame( array( 'local_pickup:9' ), $this->rates_for( self::ADDRESS ) );

		// So the pickup form must not promise delivery for an address.
		$this->assertFalse( Lafka_Pickup_Checkout::delivery_possible() );
	}

	public function test_over_the_minimum_the_address_turns_the_delivery_choice_into_the_real_rate(): void {
		$this->contents = 51.98;

		$this->assertSame( array( 'local_pickup:9', 'lafka_delivery_pending' ), $this->rates_for( array( 'country' => 'CA' ) ), 'No address: Delivery is a choice, priced later.' );
		$this->assertSame( array( 'local_pickup:9', 'distance_rate:8' ), $this->rates_for( self::ADDRESS ), 'Address: the real delivery rate.' );
		$this->assertTrue( Lafka_Pickup_Checkout::delivery_possible() );
	}

	public function test_the_offer_can_be_forced_either_way(): void {
		$this->filters['lafka_pickup_checkout_offer_delivery'] = static fn() => true;
		$this->assertTrue( Lafka_Pickup_Checkout::delivery_possible() );
	}

	public function test_a_typed_street_survives_a_map_plugin_that_blanks_it(): void {
		$order = new class() {
			public array $a = array(
				'billing_address_1'  => '',
				'shipping_address_1' => '',
			);
			public int $saved = 0;
			public function get_billing_address_1() {
				return $this->a['billing_address_1'];
			}
			public function get_shipping_address_1() {
				return $this->a['shipping_address_1'];
			}
			public function set_billing_address_1( $v ) {
				$this->a['billing_address_1'] = $v;
			}
			public function set_shipping_address_1( $v ) {
				$this->a['shipping_address_1'] = $v;
			}
			public function save() {
				++$this->saved;
			}
		};

		Lafka_Order_Path::restore_order_street( 1, array( 'billing_address_1' => '100 Example Rd' ), $order );

		$this->assertSame( '100 Example Rd', $order->a['billing_address_1'] );
		$this->assertSame( '100 Example Rd', $order->a['shipping_address_1'], 'Ship to billing: the same street.' );
		$this->assertSame( 1, $order->saved );

		$order->a['shipping_address_1'] = 'Map plugin street';
		Lafka_Order_Path::restore_order_street( 1, array( 'billing_address_1' => 'Typed' ), $order );
		$this->assertSame( 'Map plugin street', $order->a['shipping_address_1'], 'A street already on the order is never overwritten.' );
		$this->assertSame( 1, $order->saved, 'Nothing to restore, nothing saved.' );
	}
}
