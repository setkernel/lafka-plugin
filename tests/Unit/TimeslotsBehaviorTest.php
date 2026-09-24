<?php
/**
 * Behavioural coverage for Lafka_Timeslots and its Store API adapter.
 *
 * Each test drives the real class with Brain Monkey stubs for the WP/WC
 * functions it touches, and asserts on the decision it returns. Orders live
 * in an in-memory store that honours the status list and meta_query the
 * class passes to wc_get_orders().
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTime;
use DateTimeZone;
use Lafka_Order_Hours;
use Lafka_Store_Api;
use Lafka_Timeslots;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class TimeslotsBehaviorTest extends TestCase {

	private const DATE_GONE = 'The selected Delivery/Pickup date is no longer available. Please choose another.';
	private const SLOT_GONE = 'The selected Delivery/Pickup time is no longer available. Please choose another.';
	private const SLOT_FULL = 'The selected Delivery/Pickup time is fully booked. Please choose another.';
	private const REQUIRED  = 'Please enter Delivery/Pickup time.';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var list<array{status: string, meta: array<string, string>}> */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_get_orders' )->alias( fn( $args ) => $this->query_orders( $args ) );
		require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
		require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
		require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';

		Lafka_Order_Hours::$timezone                                = '';
		Lafka_Order_Hours::$lafka_order_hours_schedule              = '';
		Lafka_Order_Hours::$lafka_order_hours_holidays_calendar     = '';
		Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
	}

	protected function tearDown(): void {
		unset( $_POST['lafka_checkout_date'], $_POST['lafka_checkout_timeslot'], $_POST['date'] );
		Lafka_Order_Hours::$lafka_order_hours_schedule          = null;
		Lafka_Order_Hours::$lafka_order_hours_holidays_calendar = '';
		$this->set_singleton( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Lafka_Timeslots without running the hook-registering constructor,
	 * hydrate it from the stubbed options, and install it as the singleton.
	 */
	private function timeslots(): Lafka_Timeslots {
		$instance = ( new ReflectionClass( Lafka_Timeslots::class ) )->newInstanceWithoutConstructor();
		$instance->init_order_date_time_options();
		$this->set_singleton( $instance );
		return $instance;
	}

	private function set_singleton( ?Lafka_Timeslots $instance ): void {
		$prop = ( new ReflectionClass( Lafka_Timeslots::class ) )->getProperty( '_instance' );
		$prop->setValue( null, $instance );
	}

	private function store_api_timeslot_error(): ?string {
		$method = ( new ReflectionClass( Lafka_Store_Api::class ) )->getMethod( 'timeslot_error' );
		return $method->invoke( null, array() );
	}

	/**
	 * Date/time feature on, 7 days ahead, hourly slots.
	 *
	 * @param array<string, mixed> $overrides Option overrides.
	 */
	private function enable_datetime( array $overrides = array() ): void {
		$this->options['lafka_shipping_areas_datetime'] = array_merge(
			array(
				'enable_datetime_option' => '1',
				'datetime_mandatory'     => '1',
				'days_ahead'             => 7,
				'timeslot_duration'      => 60,
			),
			$overrides
		);
	}

	/**
	 * Open 12:00-14:00 every day except the listed weekdays (0 = Monday).
	 *
	 * @param int[] $closed_weekdays Weekday indexes with no periods.
	 */
	private function set_schedule( array $closed_weekdays = array() ): void {
		$days = array();
		foreach ( range( 0, 6 ) as $i ) {
			$days[] = array(
				'periods' => in_array( $i, $closed_weekdays, true ) ? array() : array(
					array(
						'start' => '12:00',
						'end'   => '14:00',
					),
				),
			);
		}
		Lafka_Order_Hours::$lafka_order_hours_schedule = (string) json_encode( $days );
	}

	private static function day( int $offset ): DateTime {
		return new DateTime( ( $offset >= 0 ? '+' : '' ) . $offset . ' days', new DateTimeZone( 'UTC' ) );
	}

	/** @param array<string, mixed> $args wc_get_orders() arguments. */
	private function query_orders( array $args ): array {
		$statuses = (array) ( $args['status'] ?? array() );
		return array_keys(
			array_filter(
				$this->orders,
				static fn( $order ) => in_array( $order['status'], $statuses, true )
					&& self::meta_query_matches( $args['meta_query'], $order['meta'] )
			)
		);
	}

	/**
	 * Evaluate a WP meta_query (AND/OR nesting, '=', 'IN', 'NOT EXISTS')
	 * against one order's meta map — a stand-in for the order datastore.
	 *
	 * @param array<string, string> $meta Order meta.
	 */
	private static function meta_query_matches( array $query, array $meta ): bool {
		$relation = strtoupper( $query['relation'] ?? 'AND' );
		unset( $query['relation'] );
		$results = array();
		foreach ( $query as $clause ) {
			if ( ! isset( $clause['key'] ) ) {
				$results[] = self::meta_query_matches( $clause, $meta );
				continue;
			}
			$exists  = array_key_exists( $clause['key'], $meta );
			$compare = $clause['compare'] ?? '=';
			if ( 'NOT EXISTS' === $compare ) {
				$results[] = ! $exists;
			} elseif ( 'IN' === $compare ) {
				$results[] = $exists && in_array( $meta[ $clause['key'] ], array_map( 'strval', $clause['value'] ), true );
			} else {
				$results[] = $exists && (string) $meta[ $clause['key'] ] === (string) $clause['value'];
			}
		}
		return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	public function test_orders_stored_with_an_empty_branch_count_against_the_no_branch_slot(): void {
		$slot   = array(
			'lafka_checkout_date'     => '2031-01-15',
			'lafka_checkout_timeslot' => '12:00 - 13:00',
		);
		$orders = array(
			$slot,                                              // No branch meta.
			$slot + array( 'lafka_selected_branch_id' => '' ),  // Legacy empty value.
			$slot + array( 'lafka_selected_branch_id' => '5' ), // Branch 5.
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_get_orders' )->alias(
			static function ( $args ) use ( $orders ) {
				return array_keys(
					array_filter( $orders, static fn( $meta ) => self::meta_query_matches( $args['meta_query'], $meta ) )
				);
			}
		);
		$count = static function ( $branch_id ) {
			$method = ( new ReflectionClass( Lafka_Timeslots::class ) )->getMethod( 'get_number_of_orders_per_timeslot' );
			return $method->invoke(
				null,
				$branch_id,
				new \DateTime( '2031-01-15' ),
				array(
					'start' => '12:00',
					'end'   => '13:00',
				)
			);
		};

		$this->assertSame( 2, $count( null ), 'Both branch-less orders occupy the no-branch slot.' );
		$this->assertSame( 1, $count( 5 ) );
	}

	public function test_offered_dates_start_from_today_on_the_store_clock(): void {
		// UTC+14 and UTC-12 are always on different calendar days, so a list
		// built on any single clock (e.g. UTC) cannot satisfy both.
		foreach ( array( 'Pacific/Kiritimati', 'Etc/GMT+12' ) as $zone ) {
			$tz = new \DateTimeZone( $zone );
			Functions\when( 'wp_timezone' )->justReturn( $tz );

			$this->assertSame(
				array( ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d' ) ),
				Lafka_Timeslots::get_all_days_ahead_public( 0 ),
				"Today must be today in $zone."
			);
		}
	}

	public function test_saved_mandatory_is_inert_while_feature_is_off(): void {
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '',
			'datetime_mandatory'     => '1',
		);

		$this->assertFalse( $this->timeslots()->is_mandatory() );
		$this->assertNull(
			$this->store_api_timeslot_error(),
			'Block checkout must be placeable when the date/time feature is off, whatever "mandatory" says.'
		);
	}

	public function test_mandatory_blocks_an_empty_selection_while_feature_is_on(): void {
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '1',
			'datetime_mandatory'     => '1',
		);

		$this->assertTrue( $this->timeslots()->is_mandatory() );
		$this->assertSame( 'Please enter Delivery/Pickup time.', $this->store_api_timeslot_error() );
	}

	public function test_optional_store_accepts_an_empty_selection(): void {
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '1',
			'datetime_mandatory'     => '',
		);

		$this->assertFalse( $this->timeslots()->is_mandatory() );
		$this->assertNull( $this->store_api_timeslot_error() );
	}

	/* ------------------------------------------------------------------ *
	 *  Submit-time decision (evaluate_datetime_selection) — shared by the
	 *  classic checkout and the Store API.
	 * ------------------------------------------------------------------ */

	public function test_only_a_slot_the_server_would_offer_is_accepted(): void {
		$this->enable_datetime();
		$open    = self::day( 2 );
		$closed  = self::day( 3 );
		$holiday = self::day( 4 );
		$this->set_schedule( array( (int) $closed->format( 'N' ) - 1 ) );
		Lafka_Order_Hours::$lafka_order_hours_holidays_calendar = $holiday->format( 'Y-m-d' );
		$timeslots = $this->timeslots();

		$submissions = array(
			'rendered slot on an open day' => array( $open->format( 'Y-m-d' ), '12:00 - 13:00' ),
			'last slot of the period'      => array( $open->format( 'Y-m-d' ), '13:00 - 14:00' ),
			'past date'                    => array( self::day( -1 )->format( 'Y-m-d' ), '12:00 - 13:00' ),
			'closed weekday'               => array( $closed->format( 'Y-m-d' ), '12:00 - 13:00' ),
			'vacation day'                 => array( $holiday->format( 'Y-m-d' ), '12:00 - 13:00' ),
			'beyond the days-ahead window' => array( self::day( 9 )->format( 'Y-m-d' ), '12:00 - 13:00' ),
			'not a date'                   => array( 'next-friday', '12:00 - 13:00' ),
			'crafted slot id'              => array( $open->format( 'Y-m-d' ), '12:30 - 13:30' ),
			'slot outside opening hours'   => array( $open->format( 'Y-m-d' ), '20:00 - 21:00' ),
		);
		$actual      = array();
		foreach ( $submissions as $label => list( $date, $slot ) ) {
			$actual[ $label ] = $timeslots->evaluate_datetime_selection( $date, $slot, true );
		}

		$this->assertSame(
			array(
				'rendered slot on an open day' => null,
				'last slot of the period'      => null,
				'past date'                    => self::DATE_GONE,
				'closed weekday'               => self::DATE_GONE,
				'vacation day'                 => self::DATE_GONE,
				'beyond the days-ahead window' => self::DATE_GONE,
				'not a date'                   => self::DATE_GONE,
				'crafted slot id'              => self::SLOT_GONE,
				'slot outside opening hours'   => self::SLOT_GONE,
			),
			$actual
		);
	}

	public function test_presence_rules_for_mandatory_and_optional_stores(): void {
		$this->enable_datetime();
		$this->set_schedule();
		$timeslots = $this->timeslots();
		$date      = self::day( 2 )->format( 'Y-m-d' );

		$this->assertSame( self::REQUIRED, $timeslots->evaluate_datetime_selection( '', '', true ) );
		$this->assertSame( self::REQUIRED, $timeslots->evaluate_datetime_selection( $date, '', true ), 'Mandatory needs the slot too.' );
		$this->assertNull( $timeslots->evaluate_datetime_selection( '', '', false ), 'Optional store, nothing chosen.' );
		$this->assertNull( $timeslots->evaluate_datetime_selection( $date, '', false ), 'Optional store, date only.' );
		$this->assertSame(
			'Please select a Delivery/Pickup date.',
			$timeslots->evaluate_datetime_selection( '', '12:00 - 13:00', false ),
			'A slot without its date cannot be counted against capacity.'
		);
	}

	public function test_a_slot_at_capacity_is_rejected_at_submit_across_kds_statuses(): void {
		$this->enable_datetime( array( 'orders_per_timeslot' => 2 ) );
		$this->set_schedule();
		$date   = self::day( 2 );
		$booked = array(
			'lafka_checkout_date'     => $date->format( 'Y-m-d' ),
			'lafka_checkout_timeslot' => '12:00 - 13:00',
		);
		// Orders the KDS has moved on still occupy the slot; a cancelled one does not.
		$this->orders = array(
			array(
				'status' => 'wc-accepted',
				'meta'   => $booked,
			),
			array(
				'status' => 'wc-cancelled',
				'meta'   => $booked,
			),
		);
		$timeslots    = $this->timeslots();

		$this->assertNull(
			$timeslots->evaluate_datetime_selection( $date->format( 'Y-m-d' ), '12:00 - 13:00', true ),
			'One live order out of two seats: still bookable.'
		);

		$this->orders[] = array(
			'status' => 'wc-ready',
			'meta'   => $booked,
		);

		$this->assertSame( self::SLOT_FULL, $timeslots->evaluate_datetime_selection( $date->format( 'Y-m-d' ), '12:00 - 13:00', true ) );
		$this->assertNull( $timeslots->evaluate_datetime_selection( $date->format( 'Y-m-d' ), '13:00 - 14:00', true ), 'Other slots stay open.' );

		$offered = Lafka_Timeslots::get_timeslots_for_date( $date, 60 );
		$this->assertTrue( $offered[0]['disabled'], 'The dropdown greys the full slot out too.' );
		$this->assertFalse( $offered[1]['disabled'] );
	}

	public function test_a_store_without_an_order_hours_schedule_accepts_any_slot_in_the_window(): void {
		$this->enable_datetime();
		$timeslots = $this->timeslots();

		$this->assertNull( $timeslots->evaluate_datetime_selection( self::day( 2 )->format( 'Y-m-d' ), '10:00 - 11:00', true ) );
		$this->assertSame( self::DATE_GONE, $timeslots->evaluate_datetime_selection( self::day( 9 )->format( 'Y-m-d' ), '10:00 - 11:00', true ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Classic checkout wrapper + order meta
	 * ------------------------------------------------------------------ */

	public function test_classic_checkout_raises_the_decision_as_a_blocking_error_notice(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		$this->enable_datetime();
		$this->set_schedule();
		$timeslots                        = $this->timeslots();
		$_POST['lafka_checkout_date']     = self::day( 2 )->format( 'Y-m-d' );
		$_POST['lafka_checkout_timeslot'] = '12:30 - 13:30';
		$notices                          = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( $message, $type ) use ( &$notices ) {
				$notices[] = array( $message, $type );
			}
		);

		$timeslots->validate_datetime_fields();
		$_POST['lafka_checkout_timeslot'] = '12:00 - 13:00';
		$timeslots->validate_datetime_fields();

		$this->assertSame( array( array( self::SLOT_GONE, 'error' ) ), $notices, 'Only the crafted slot raises an error notice.' );
	}

	public function test_the_chosen_slot_is_written_to_the_order(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST['lafka_checkout_date']     = '2031-01-15';
		$_POST['lafka_checkout_timeslot'] = '12:00 - 13:00';
		$written                          = array();
		$order                            = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
			}
		);

		$this->timeslots()->checkout_datetime_update_order_meta( $order );

		$this->assertSame(
			array(
				'lafka_checkout_date'     => '2031-01-15',
				'lafka_checkout_timeslot' => '12:00 - 13:00',
			),
			$written
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Public time_slots_for_date AJAX endpoint
	 * ------------------------------------------------------------------ */

	/** @return array<string, array{0: string|null}> */
	public static function malformed_ajax_dates(): array {
		return array(
			'missing'          => array( null ),
			'empty'            => array( '' ),
			'not a date'       => array( 'garbage' ),
			'impossible date'  => array( '2031-02-30' ),
			'unpadded date'    => array( '2031-1-5' ),
			'trailing content' => array( '2031-01-05 extra' ),
		);
	}

	#[DataProvider( 'malformed_ajax_dates' )]
	public function test_ajax_endpoint_answers_400_for_a_malformed_date( ?string $date ): void {
		$this->stub_ajax();
		if ( null !== $date ) {
			$_POST['date'] = $date;
		}

		$this->assertSame( 'error:400', $this->call_ajax() );
	}

	public function test_ajax_endpoint_returns_the_slots_for_a_valid_date(): void {
		$this->stub_ajax();
		$this->enable_datetime();
		$this->set_schedule();
		$_POST['date'] = self::day( 2 )->format( 'Y-m-d' );

		$this->assertSame( 'success:12:00 - 13:00,13:00 - 14:00', $this->call_ajax() );
	}

	private function stub_ajax(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data, $status = null ) {
				throw new RuntimeException( 'error:' . $status );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $slots ) {
				throw new RuntimeException( 'success:' . implode( ',', array_column( $slots, 'id' ) ) );
			}
		);
	}

	private function call_ajax(): string {
		try {
			$this->timeslots()->retrieve_time_slots_for_date();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return 'no response';
	}
}
