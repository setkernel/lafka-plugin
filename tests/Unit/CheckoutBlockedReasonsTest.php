<?php
/**
 * GX1 / A7: every Lafka refusal point fires
 * `lafka_checkout_blocked( string $reason, array $context )` with the right
 * reason from the documented vocabulary — order hours (classic checkout,
 * classic + Store API add-to-cart), shipping-areas geo-fence, timeslots, the
 * Store API update callback + cart errors (only on a place-order request),
 * add-ons (classic + Store API) and the promotions delivery minimum.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	if ( ! class_exists( RouteException::class ) ) {
		class RouteException extends \RuntimeException { // phpcs:ignore
			public string $error_code;
			public function __construct( $error_code, $message, $http_status_code = 400 ) {
				$this->error_code = (string) $error_code;
				parent::__construct( (string) $message, (int) $http_status_code );
			}
			public function getErrorCode(): string {
				return $this->error_code;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Checkout_Block_Reasons;
	use Lafka_Checkout_Failures;
	use Lafka_Order_Hours;
	use Lafka_Promotions;
	use Lafka_Shipping_Areas;
	use Lafka_Store_Api;
	use Lafka_Timeslots;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;

	require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-block-reasons.php';

	final class CheckoutBlockedReasonsTest extends TestCase {

		/** @var array<int,array{0:string,1:array}> Captured lafka_checkout_blocked calls. */
		private array $blocked = array();

		/** @var array<string,mixed> */
		private array $options = array();

		/** @var array<string,mixed> */
		private array $session = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Lafka_Checkout_Block_Reasons::reset();
			// The Store API stage ('cart' vs 'checkout') reads a per-request
			// static another test file may have left set: start from a clean request.
			if ( class_exists( 'Lafka_Checkout_Failures', false ) ) {
				Lafka_Checkout_Failures::reset();
			}
			$this->blocked = array();
			$this->options = array();
			$this->session = array();

			Functions\when( 'do_action' )->alias(
				function ( $hook, ...$args ) {
					if ( 'lafka_checkout_blocked' === $hook ) {
						$this->blocked[] = array( $args[0], $args[1] );
					}
				}
			);
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'wc_add_notice' )->justReturn( null );
			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_terms' )->justReturn( array() );
			Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

			$session = new class( $this ) {
				public function __construct( private $test ) {}
				public function get( $key ) {
					return $this->test->session_value( $key );
				}
				public function set( $key, $value ) {
					$this->test->session_set( $key, $value );
				}
			};
			$cart    = new class() {
				public function needs_shipping() {
					return true;
				}
			};
			Functions\when( 'WC' )->justReturn(
				(object) array(
					'session' => $session,
					'cart'    => $cart,
				)
			);
		}

		protected function tearDown(): void {
			unset( $_POST['lafka_picked_delivery_geocoded'], $_POST['shipping_method'], $_POST['lafka_checkout_date'], $_POST['lafka_checkout_timeslot'] );
			if ( class_exists( 'Lafka_Checkout_Failures', false ) ) {
				Lafka_Checkout_Failures::reset();
			}
			Lafka_Checkout_Block_Reasons::reset();
			Monkey\tearDown();
			parent::tearDown();
		}

		/** Session double reader. */
		public function session_value( string $key ) {
			return $this->session[ $key ] ?? null;
		}

		/** Session double writer. */
		public function session_set( string $key, $value ): void {
			$this->session[ $key ] = $value;
		}

		/** @return array<int,string> Reasons fired so far. */
		private function reasons(): array {
			return array_column( $this->blocked, 0 );
		}

		// ─── Vocabulary contract ────────────────────────────────────────────

		public function test_vocabulary_is_unique_labelled_and_maps_every_lafka_code(): void {
			$all = Lafka_Checkout_Block_Reasons::all();

			self::assertSame( $all, array_values( array_unique( $all ) ) );
			foreach ( $all as $reason ) {
				self::assertNotSame( $reason, Lafka_Checkout_Block_Reasons::label( $reason ), "$reason needs a label" );
			}
			foreach ( Lafka_Checkout_Block_Reasons::CODE_MAP as $code => $reason ) {
				self::assertContains( $reason, $all, "$code maps outside the vocabulary" );
			}
			foreach ( Lafka_Checkout_Block_Reasons::PAYMENT_CLASS_MAP as $reason ) {
				self::assertTrue( Lafka_Checkout_Block_Reasons::is_payment( $reason ) );
			}
			self::assertFalse( Lafka_Checkout_Block_Reasons::is_payment( Lafka_Checkout_Block_Reasons::STORE_CLOSED ) );
		}

		public function test_emit_fires_once_per_reason_and_path_and_ignores_unknown_reasons(): void {
			self::assertTrue( Lafka_Checkout_Block_Reasons::emit( 'store_closed', array( 'stage' => 'checkout' ) ) );
			self::assertFalse( Lafka_Checkout_Block_Reasons::emit( 'store_closed', array( 'stage' => 'checkout' ) ) );
			self::assertTrue( Lafka_Checkout_Block_Reasons::emit( 'store_closed', array( 'path' => 'store_api' ) ) );
			self::assertFalse( Lafka_Checkout_Block_Reasons::emit( 'not_a_reason' ) );

			self::assertSame( array( 'store_closed', 'store_closed' ), $this->reasons() );
			self::assertSame( 'classic', $this->blocked[0][1]['path'], 'path defaults to classic' );
		}

		// ─── Order hours ────────────────────────────────────────────────────

		private function closed_store( bool $disable_add_to_cart = false ): Lafka_Order_Hours {
			require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
			Lafka_Order_Hours::$timezone                                = '';
			Lafka_Order_Hours::$lafka_order_hours_schedule              = '';
			Lafka_Order_Hours::$lafka_order_hours_holidays_calendar     = '';
			Lafka_Order_Hours::$lafka_order_hours_force_override_check  = true;
			Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
			Lafka_Order_Hours::$lafka_order_hours_options               = $disable_add_to_cart
				? array( 'lafka_order_hours_disable_add_to_cart' => '1' )
				: array();
			return ( new ReflectionClass( Lafka_Order_Hours::class ) )->newInstanceWithoutConstructor();
		}

		public function test_closed_store_reports_store_closed_at_classic_checkout(): void {
			$this->closed_store()->gate_checkout_when_closed();

			self::assertSame( array( 'store_closed' ), $this->reasons() );
			self::assertSame( 'classic', $this->blocked[0][1]['path'] );
			self::assertSame( 'checkout', $this->blocked[0][1]['stage'] );
			self::assertSame( 'lafka_store_closed', $this->blocked[0][1]['code'] );
		}

		public function test_closed_store_reports_blocked_add_to_cart_on_both_paths(): void {
			$hours = $this->closed_store( true );

			self::assertFalse( $hours->gate_add_to_cart_when_closed( true ) );
			try {
				$hours->gate_store_api_add_to_cart_when_closed();
				self::fail( 'Store API add-to-cart must be rejected.' );
			} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
				self::assertSame( 'lafka_store_closed', $e->getErrorCode() );
			}

			self::assertSame( array( 'store_closed', 'store_closed' ), $this->reasons() );
			self::assertSame( array( 'classic', 'store_api' ), array( $this->blocked[0][1]['path'], $this->blocked[1][1]['path'] ) );
			self::assertSame( 'add_to_cart', $this->blocked[1][1]['stage'] );
		}

		public function test_open_store_reports_nothing(): void {
			$hours = $this->closed_store();
			Lafka_Order_Hours::$lafka_order_hours_force_override_status = '1';

			$hours->gate_checkout_when_closed();

			self::assertSame( array(), $this->blocked );
			Lafka_Order_Hours::$lafka_order_hours_force_override_check = false;
		}

		// ─── Shipping areas (classic geo-fence) ─────────────────────────────

		private function shipping_areas(): Lafka_Shipping_Areas {
			require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
			require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
			$this->options['lafka_shipping_areas_general'] = array(
				'pick_delivery_address'     => '1',
				'mandatory_pickup_delivery' => '1',
			);
			$this->session['chosen_shipping_methods']      = array( 'flat_rate:1' );
			return ( new ReflectionClass( Lafka_Shipping_Areas::class ) )->newInstanceWithoutConstructor();
		}

		public function test_missing_or_invalid_pinpoint_reports_address_unpinned(): void {
			$areas = $this->shipping_areas();

			$areas->validate_checkout_field_process();
			self::assertSame( array( 'address_unpinned' ), $this->reasons() );

			Lafka_Checkout_Block_Reasons::reset();
			$_POST['lafka_picked_delivery_geocoded'] = '{"lat":"x"}';
			$areas->validate_checkout_field_process();
			self::assertSame( array( 'address_unpinned', 'address_unpinned' ), $this->reasons() );
		}

		public function test_out_of_zone_pinpoint_reports_outside_delivery_zone(): void {
			$areas = $this->shipping_areas();
			Functions\when( 'get_posts' )->justReturn( array( (object) array( 'ID' => 1 ) ) );
			Functions\when( 'get_post_meta' )->justReturn( '_p~iF~ps|U_ulLnnqC_mqNvxq`@' );
			$_POST['lafka_picked_delivery_geocoded'] = '{"lat":0,"lng":0}';

			$areas->validate_checkout_field_process();

			self::assertSame( array( 'outside_delivery_zone' ), $this->reasons() );
			self::assertSame( 'lafka_outside_delivery_area', $this->blocked[0][1]['code'] );
		}

		public function test_pickup_order_is_never_refused_for_the_pinpoint(): void {
			$areas                                    = $this->shipping_areas();
			$this->session['chosen_shipping_methods'] = array( 'local_pickup:3' );

			$areas->validate_checkout_field_process();

			self::assertSame( array(), $this->blocked );
		}

		// ─── Timeslots (classic) ────────────────────────────────────────────

		public function test_invalid_timeslot_reports_timeslot_invalid(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
			require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
			Lafka_Order_Hours::$lafka_order_hours_force_override_check = false;
			Lafka_Order_Hours::$lafka_order_hours_schedule             = '';
			$this->options['lafka_shipping_areas_datetime']            = array(
				'enable_datetime_option' => '1',
				'datetime_mandatory'     => '1',
				'days_ahead'             => 7,
				'timeslot_duration'      => 60,
			);
			$timeslots = ( new ReflectionClass( Lafka_Timeslots::class ) )->newInstanceWithoutConstructor();
			$timeslots->init_order_date_time_options();

			$timeslots->validate_datetime_fields(); // mandatory, nothing chosen.

			self::assertSame( array( 'timeslot_invalid' ), $this->reasons() );
			self::assertSame( 'lafka_invalid_timeslot', $this->blocked[0][1]['code'] );
		}

		// ─── Store API ──────────────────────────────────────────────────────

		public function test_store_api_update_callback_rejections_are_reported(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';

			try {
				Lafka_Store_Api::handle_update_callback( array( 'checkout_timeslot' => '12:00 - 13:00' ) ); // slot without a date.
				self::fail( 'A malformed slot must be rejected.' );
			} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
				self::assertSame( 'lafka_invalid_timeslot', $e->getErrorCode() );
			}

			self::assertSame( array( 'timeslot_invalid' ), $this->reasons() );
			self::assertSame( 'store_api', $this->blocked[0][1]['path'] );
			self::assertSame( 'cart', $this->blocked[0][1]['stage'] );
		}

		public function test_store_api_cart_errors_are_reported_only_on_a_place_order_request(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
			require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';
			require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-failures.php';
			$this->closed_store();
			$errors = new class() {
				/** @var array<int,string> */
				public array $codes = array();
				public function add( $code, $message ) {
					$this->codes[] = $code;
				}
				public function get_error_codes() {
					return $this->codes;
				}
			};

			Lafka_Store_Api::add_cart_errors( $errors ); // a cart read.
			self::assertSame( array( 'lafka_store_closed' ), $errors->codes );
			self::assertSame( array(), $this->blocked, 'A cart read is not an order attempt.' );

			$request = new class() {
				public function get_route() {
					return '/wc/store/v1/checkout';
				}
				public function get_method() {
					return 'POST';
				}
			};
			Lafka_Checkout_Failures::on_rest_before( null, null, $request );
			Lafka_Store_Api::add_cart_errors( $errors );

			self::assertSame( array( 'store_closed' ), $this->reasons() );
			self::assertSame( 'checkout', $this->blocked[0][1]['stage'] );
		}

		// ─── Add-ons ────────────────────────────────────────────────────────

		public function test_invalid_addon_selection_is_reported_on_the_store_api_path(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';
			Functions\when( 'wp_slash' )->returnArg();
			Functions\when( 'sanitize_title' )->alias( fn( $s ) => strtolower( (string) preg_replace( '/[^a-z0-9-]+/i', '-', (string) $s ) ) );
			$product = \Mockery::mock( 'WC_Product' );
			$product->shouldReceive( 'is_type' )->andReturn( false );
			$product->shouldReceive( 'get_id' )->andReturn( 94 );
			Functions\when( 'wc_get_product' )->justReturn( $product );
			$engine_cart = \Mockery::mock( \Lafka_Engine_Cart::class );
			$engine_cart->shouldReceive( 'add_cart_item_data' )->andThrow( new \Exception( '"Sauce" is a required field.' ) );

			try {
				( new \Lafka_Engine_Store_Api( $engine_cart ) )->inject_addon_selections(
					array( 'id' => 94 ),
					array(
						'id'         => 94,
						'quantity'   => 1,
						'extensions' => array( 'lafka' => array( 'addons' => array( '94-sauce-0' => '' ) ) ),
					)
				);
				self::fail( 'An invalid add-on selection must be rejected.' );
			} catch ( \RuntimeException $e ) {
				self::assertStringContainsString( 'required', $e->getMessage() );
			}

			self::assertSame( array( 'addon_invalid' ), $this->reasons() );
			self::assertSame( 'store_api', $this->blocked[0][1]['path'] );
			self::assertSame( 'add_to_cart', $this->blocked[0][1]['stage'] );
		}

		// ─── Promotions delivery minimum ────────────────────────────────────

		public function test_delivery_minimum_reports_once_when_delivery_rates_are_withheld(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';
			$promotions = ( new ReflectionClass( Lafka_Promotions::class ) )->newInstanceWithoutConstructor();
			Lafka_Promotions::flush_knobs();
			$rates = array(
				'flat_rate:1'    => (object) array( 'method_id' => 'flat_rate' ),
				'local_pickup:2' => (object) array( 'method_id' => 'local_pickup' ),
			);

			$kept = $promotions->apply_delivery_minimum( $rates, array( 'contents_cost' => 5 ) );
			self::assertSame( array( 'local_pickup:2' ), array_keys( $kept ) );
			$promotions->apply_delivery_minimum( $rates, array( 'contents_cost' => 6 ) );

			self::assertSame( array( 'below_delivery_minimum' ), $this->reasons() );
			self::assertSame( 'cart', $this->blocked[0][1]['stage'] );
		}

		public function test_delivery_minimum_is_silent_when_the_cart_qualifies(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';
			$promotions = ( new ReflectionClass( Lafka_Promotions::class ) )->newInstanceWithoutConstructor();
			Lafka_Promotions::flush_knobs();

			$promotions->apply_delivery_minimum( array( 'flat_rate:1' => (object) array( 'method_id' => 'flat_rate' ) ), array( 'contents_cost' => 500 ) );

			self::assertSame( array(), $this->blocked );
		}
	}
}
