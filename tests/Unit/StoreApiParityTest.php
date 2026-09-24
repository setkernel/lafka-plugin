<?php
/**
 * StoreApiParityTest — the Store API (block cart/checkout, headless) path
 * enforces the same ordering gates as the classic checkout: store closed,
 * branch/order-type validity, timeslot validity, and the order meta a block
 * order carries.
 *
 * Drives Lafka_Store_Api against the real Lafka_Order_Hours,
 * Lafka_Branch_Locations and Lafka_Timeslots with an in-memory WC session.
 * Hook registration is covered by CheckoutGateHookWiringTest.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeZone;
use Lafka_Order_Hours;
use Lafka_Store_Api;
use Lafka_Timeslots;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class StoreApiParityTest extends TestCase {

	private const NO_SUCH_BRANCH = 'Something is wrong. No such branch location.';
	private const TYPE_REFUSED   = 'The selected order type is not available for this branch.';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> WC session contents. */
	private array $session = array();

	/** @var array<int, array<string, string>> */
	private array $term_meta = array();

	/** @var array<int, string> Orderable (legit) branches, id => name. */
	private array $legit_branches = array();

	/** @var array<int, string> Taxonomy each term id really belongs to. */
	private array $term_taxonomy = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'is_lafka_shipping_areas' )->justReturn( false );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'get_option' )->alias( fn( $key, $fallback = false ) => $this->options[ $key ] ?? $fallback );
		Functions\when( 'get_term_meta' )->alias( fn( $id, $key ) => $this->term_meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_terms' )->alias( fn() => $this->legit_branches );
		Functions\when( 'get_term' )->alias(
			fn( $id, $taxonomy = '' ) => ( $this->term_taxonomy[ $id ] ?? null ) === $taxonomy
				? (object) array(
					'term_id'  => $id,
					'taxonomy' => $taxonomy,
				)
				: null
		);
		$session = new class( $this ) {
			public function __construct( private StoreApiParityTest $test ) {}
			public function get( $key ) {
				return $this->test->session_get( $key );
			}
			public function set( $key, $value ) {
				$this->test->session_set( $key, $value );
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
		require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
		require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';

		Lafka_Order_Hours::$lafka_order_hours_options               = array();
		Lafka_Order_Hours::$timezone                                = '';
		Lafka_Order_Hours::$lafka_order_hours_schedule              = '';
		Lafka_Order_Hours::$lafka_order_hours_holidays_calendar     = '';
		Lafka_Order_Hours::$lafka_order_hours_force_override_check  = true;
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '1'; // Open.
	}

	protected function tearDown(): void {
		$this->set_timeslots_singleton( null );
		Lafka_Order_Hours::$lafka_order_hours_options               = null;
		Lafka_Order_Hours::$lafka_order_hours_schedule              = null;
		Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
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

	private function set_timeslots_singleton( ?Lafka_Timeslots $instance ): void {
		( new ReflectionClass( Lafka_Timeslots::class ) )->getProperty( '_instance' )->setValue( null, $instance );
	}

	/** Branch 5 exists, is orderable, and offers pickup only. */
	private function pickup_only_branch(): void {
		$this->term_taxonomy[5]  = 'lafka_branch_location';
		$this->legit_branches[5] = 'Downtown';
		$this->term_meta[5]      = array( 'lafka_branch_order_type' => 'pickup' );
	}

	/** @return list<array{0: string, 1: string}> Errors add_cart_errors() raised. */
	private function cart_errors(): array {
		$errors = new class() {
			/** @var list<array{0: string, 1: string}> */
			public array $added = array();
			public function add( $code, $message ) {
				$this->added[] = array( $code, $message );
			}
		};
		Lafka_Store_Api::add_cart_errors( $errors );
		return $errors->added;
	}

	/* ----------------------------------------------------------------- *
	 *  Registration
	 * ----------------------------------------------------------------- */

	public function test_register_wires_cart_schema_and_update_callback(): void {
		$captured = array();
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured['schema'] = $args;
				return true;
			}
		);
		Functions\when( 'woocommerce_store_api_register_update_callback' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured['callback'] = $args;
				return true;
			}
		);

		Lafka_Store_Api::register();

		$this->assertSame( 'cart', $captured['schema']['endpoint'] ?? null, 'Schema must extend the cart endpoint.' );
		$this->assertSame( 'lafka', $captured['schema']['namespace'] );
		$this->assertIsCallable( $captured['schema']['data_callback'] );
		$this->assertIsCallable( $captured['schema']['schema_callback'] );
		$this->assertSame( 'lafka', $captured['callback']['namespace'] ?? null );
		$this->assertIsCallable( $captured['callback']['callback'] );
	}

	/* ----------------------------------------------------------------- *
	 *  Checkout gates (woocommerce_store_api_cart_errors)
	 * ----------------------------------------------------------------- */

	public function test_a_valid_cart_raises_no_errors(): void {
		$this->pickup_only_branch();
		$this->session['lafka_branch_location'] = array(
			'branch_id'  => 5,
			'order_type' => 'pickup',
		);

		$this->assertSame( array(), $this->cart_errors() );
	}

	public function test_checkout_is_blocked_while_the_store_is_closed(): void {
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
		Lafka_Order_Hours::$lafka_order_hours_options               = array( 'lafka_order_hours_message' => 'Back at noon.' );

		$this->assertSame( array( array( 'lafka_store_closed', 'Back at noon.' ) ), $this->cart_errors() );
	}

	public function test_checkout_is_blocked_for_an_order_type_the_branch_does_not_offer(): void {
		$this->pickup_only_branch();
		$this->session['lafka_branch_location'] = array(
			'branch_id'  => 5,
			'order_type' => 'delivery',
		);

		$this->assertSame( array( array( 'lafka_invalid_order_type', self::TYPE_REFUSED ) ), $this->cart_errors() );
	}

	public function test_checkout_is_blocked_for_a_date_the_store_never_offered(): void {
		$this->options['lafka_shipping_areas_datetime'] = array( 'enable_datetime_option' => '1' );
		$timeslots = ( new ReflectionClass( Lafka_Timeslots::class ) )->newInstanceWithoutConstructor();
		$timeslots->init_order_date_time_options();
		$this->set_timeslots_singleton( $timeslots );
		$this->session[ Lafka_Store_Api::DATETIME_SESSION_KEY ] = array(
			'date'     => '1999-01-01',
			'timeslot' => '12:00 - 13:00',
		);

		$this->assertSame(
			array( array( 'lafka_invalid_timeslot', 'The selected Delivery/Pickup date is no longer available. Please choose another.' ) ),
			$this->cart_errors()
		);
	}

	public function test_a_block_order_carries_the_branch_and_slot_meta(): void {
		$this->pickup_only_branch();
		$this->session['lafka_branch_location']                 = array(
			'branch_id'  => 5,
			'order_type' => 'pickup',
		);
		$this->session[ Lafka_Store_Api::DATETIME_SESSION_KEY ] = array(
			'date'     => '2031-01-15',
			'timeslot' => '12:00 - 13:00',
		);
		$written = array();
		$order   = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
			}
		);

		Lafka_Store_Api::on_checkout_update_order_from_request( $order, new \ArrayObject( array() ) );

		$this->assertSame(
			array(
				'lafka_selected_branch_id' => '5',
				'lafka_order_type'         => 'pickup',
				'lafka_checkout_date'      => '2031-01-15',
				'lafka_checkout_timeslot'  => '12:00 - 13:00',
			),
			$written
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Update callback (POST /cart/extensions, namespace lafka)
	 * ----------------------------------------------------------------- */

	/** @return array<string, array{0: array<string, mixed>, 1: string|null}> */
	public static function branch_updates(): array {
		return array(
			'orderable branch, offered type'  => array( array( 'branch_id' => 5, 'order_type' => 'pickup' ), null ),
			'term from another taxonomy'      => array( array( 'branch_id' => 8, 'order_type' => 'pickup' ), self::NO_SUCH_BRANCH ),
			'unknown term id'                 => array( array( 'branch_id' => 999, 'order_type' => 'pickup' ), self::NO_SUCH_BRANCH ),
			'branch term that is not legit'   => array( array( 'branch_id' => 6, 'order_type' => 'pickup' ), self::NO_SUCH_BRANCH ),
			'type the branch does not offer'  => array( array( 'branch_id' => 5, 'order_type' => 'delivery' ), self::TYPE_REFUSED ),
			'type that does not exist'        => array( array( 'branch_id' => 5, 'order_type' => 'teleport' ), self::TYPE_REFUSED ),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	#[DataProvider( 'branch_updates' )]
	public function test_branch_update_applies_the_select_branch_rules( array $data, ?string $error ): void {
		$this->pickup_only_branch();
		$this->term_taxonomy[8] = 'product_cat';           // Exists, wrong taxonomy.
		$this->term_taxonomy[6] = 'lafka_branch_location'; // Exists, no geocoded address.

		$this->assertSame( $error, $this->update_error( $data ) );
		$this->assertSame(
			null === $error ? array(
				'branch_id'  => 5,
				'order_type' => 'pickup',
			) : null,
			$this->session['lafka_branch_location'] ?? null,
			'Only an accepted selection reaches the session.'
		);
	}

	/** @return array<string, array{0: string, 1: string, 2: string|null}> */
	public static function timeslot_updates(): array {
		return array(
			'nothing chosen'      => array( '', '', null ),
			'well-formed pair'    => array( '2026-07-10', '12:00 - 12:30', null ),
			'slot without a date' => array( '', '12:00 - 12:30', 'Please select a Delivery/Pickup date.' ),
			'not a Y-m-d date'    => array( '10/07/2026', '', 'The selected Delivery/Pickup date is invalid. Please choose another.' ),
		);
	}

	#[DataProvider( 'timeslot_updates' )]
	public function test_timeslot_update_stores_only_a_well_formed_pair( string $date, string $slot, ?string $error ): void {
		$this->assertSame(
			$error,
			$this->update_error(
				array(
					'checkout_date'     => $date,
					'checkout_timeslot' => $slot,
				)
			)
		);
		$this->assertSame(
			null === $error ? array(
				'date'     => $date,
				'timeslot' => $slot,
			) : null,
			$this->session[ Lafka_Store_Api::DATETIME_SESSION_KEY ] ?? null
		);
	}

	/** @param array<string, mixed> $data */
	private function update_error( array $data ): ?string {
		try {
			Lafka_Store_Api::handle_update_callback( $data );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return null;
	}

	/* ----------------------------------------------------------------- *
	 *  Schema payload
	 * ----------------------------------------------------------------- */

	public function test_cart_schema_describes_every_cart_data_key(): void {
		$this->pickup_only_branch();
		$this->session['lafka_branch_location'] = array(
			'branch_id'  => 5,
			'order_type' => 'pickup',
		);
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_term' )->justReturn(
			(object) array(
				'name'     => 'Downtown',
				'taxonomy' => 'lafka_branch_location',
			)
		);

		$schema = Lafka_Store_Api::extend_cart_schema();
		$data   = Lafka_Store_Api::extend_cart_data();

		$this->assertSame( array_keys( $schema ), array_keys( $data ), 'Every exposed value must be described by the schema.' );
		$this->assertSame( 'pickup', $data['order_type'] );
		$this->assertSame( 5, $data['branch_id'] );
		$this->assertSame( 'Downtown', $data['branch_name'] );
		$this->assertTrue( $data['store_open_now'] );
	}
}
