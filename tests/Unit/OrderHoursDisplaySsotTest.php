<?php
/**
 * Regression tests for the restaurant-hours SSOT reconciliation (audit f088).
 *
 * Restaurant hours were historically stored twice: Lafka_Order_Hours' JSON
 * week schedule (the order-acceptance gate) and the per-day
 * `lafka_business_hours_*` display store (the "Open now" badge + JSON-LD).
 * Nothing kept the two in sync, so the storefront could claim "Open now"
 * (and tell Google the store is open) while ordering was blocked, or the
 * reverse.
 *
 * The fix:
 *   - Lafka_Order_Hours::get_schedule_display_hours_map() parses the gate
 *     schedule into the per-day "HH:MM-HH:MM" display shape.
 *   - lafka_get_restaurant_info() derives $info['hours'] + opening_hours from
 *     that map when the dedicated display store is unset, so badge + schema +
 *     gate all read one store. An explicitly populated display store wins.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

// Resolver under test (function definitions only — safe at include time).
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class OrderHoursDisplaySsotTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Lafka_Order_Hours.php runs `new Lafka_Order_Hours()` at the bottom,
		// whose constructor calls get_option()/add_action(). Stub get_option
		// (add_action is a no-op from the bootstrap) BEFORE the first require so
		// loading the class can't fatal. The constructor calls
		// get_option('lafka_order_hours_options') with ONE argument, while the
		// resolver passes a default, so return the default ($default_value) when
		// present and false otherwise (WP's get_option default) rather than a
		// strict returnArg(2) that would fatal on the single-argument call.
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) {
				return $default_value;
			}
		);
		if ( ! class_exists( 'Lafka_Order_Hours' ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
		}

		// Reset the shared static between tests (static state leaks otherwise).
		\Lafka_Order_Hours::$lafka_order_hours_options = null;
	}

	protected function tearDown(): void {
		\Lafka_Order_Hours::$lafka_order_hours_options = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a scheduler-format JSON schedule.
	 *
	 * @param array<int, array<int, array{0:string,1:string}>> $days Index 0..6
	 *        (Monday..Sunday) => list of [start, end] HH:MM pairs. A missing or
	 *        empty entry means that day has no periods (closed).
	 * @return string
	 */
	private static function build_schedule( array $days ): string {
		$out = array();
		foreach ( range( 0, 6 ) as $i ) {
			$periods = array();
			foreach ( $days[ $i ] ?? array() as $pair ) {
				$periods[] = array( 'start' => $pair[0], 'end' => $pair[1] );
			}
			$out[] = array(
				'day'     => $i,
				'periods' => $periods,
			);
		}
		return (string) json_encode( $out );
	}

	// ─── Lafka_Order_Hours::get_schedule_display_hours_map() ──────────────────

	public function test_map_parses_single_period_per_day(): void {
		$json = self::build_schedule(
			array(
				0 => array( array( '11:00', '23:00' ) ),
				1 => array( array( '11:00', '23:00' ) ),
				2 => array( array( '11:00', '23:00' ) ),
				3 => array( array( '11:00', '23:00' ) ),
				4 => array( array( '11:00', '23:00' ) ),
				5 => array( array( '12:00', '22:00' ) ),
				6 => array( array( '12:00', '22:00' ) ),
			)
		);

		$map = \Lafka_Order_Hours::get_schedule_display_hours_map( $json );

		self::assertSame( '11:00-23:00', $map['Monday'] );
		self::assertSame( '11:00-23:00', $map['Friday'] );
		self::assertSame( '12:00-22:00', $map['Saturday'] );
		self::assertSame( '12:00-22:00', $map['Sunday'] );
		self::assertCount( 7, $map );
	}

	public function test_map_marks_empty_days_closed(): void {
		// Monday open, the rest of the week has no periods.
		$json = self::build_schedule( array( 0 => array( array( '09:00', '17:00' ) ) ) );

		$map = \Lafka_Order_Hours::get_schedule_display_hours_map( $json );

		self::assertSame( '09:00-17:00', $map['Monday'] );
		self::assertSame( 'Closed', $map['Tuesday'] );
		self::assertSame( 'Closed', $map['Sunday'] );
	}

	public function test_map_collapses_split_periods_to_open_close_span(): void {
		// Lunch + dinner with a gap collapses to earliest-open..latest-close —
		// the single-range shape the display map / schema use.
		$json = self::build_schedule(
			array( 0 => array( array( '11:00', '14:00' ), array( '17:00', '23:00' ) ) )
		);

		$map = \Lafka_Order_Hours::get_schedule_display_hours_map( $json );

		self::assertSame( '11:00-23:00', $map['Monday'] );
	}

	public function test_map_treats_midnight_close_as_end_of_day(): void {
		// Scheduler encodes an end-of-day close as "00:00"; it must sort after
		// the open time, rendering as "24:00" (not a degenerate "18:00-00:00").
		$json = self::build_schedule( array( 0 => array( array( '18:00', '00:00' ) ) ) );

		$map = \Lafka_Order_Hours::get_schedule_display_hours_map( $json );

		self::assertSame( '18:00-24:00', $map['Monday'] );
	}

	public static function provide_unusable_schedules(): array {
		return array(
			'empty string'        => array( '' ),
			'not json'            => array( 'totally not json' ),
			'empty array'         => array( '[]' ),
			'all days no periods' => array( '[{"day":0,"periods":[]},{"day":1,"periods":[]}]' ),
		);
	}

	#[DataProvider( 'provide_unusable_schedules' )]
	public function test_map_empty_when_no_usable_schedule( string $json ): void {
		// No usable periods => empty map, so callers keep their existing
		// "no hours configured" behaviour instead of advertising a closed shop.
		self::assertSame( array(), \Lafka_Order_Hours::get_schedule_display_hours_map( $json ) );
	}

	public function test_map_reads_main_store_option_when_no_argument(): void {
		\Lafka_Order_Hours::$lafka_order_hours_options = array(
			'lafka_order_hours_schedule' => self::build_schedule(
				array( 0 => array( array( '10:00', '20:00' ) ) )
			),
		);

		$map = \Lafka_Order_Hours::get_schedule_display_hours_map();

		self::assertSame( '10:00-20:00', $map['Monday'] );
	}

	// ─── lafka_get_restaurant_info() reconciliation ───────────────────────────

	public function test_resolver_derives_hours_from_schedule_when_display_store_unset(): void {
		$this->stub_resolver_wp_functions();
		// Display store (lafka_business_hours_*) unset; only the order-hours
		// schedule is configured.
		\Lafka_Order_Hours::$lafka_order_hours_options = array(
			'lafka_order_hours_schedule' => self::build_schedule(
				array(
					0 => array( array( '11:00', '23:00' ) ),
					1 => array( array( '11:00', '23:00' ) ),
				)
			),
		);

		$info = lafka_get_restaurant_info();

		// Badge map derived from the gate schedule.
		self::assertSame( '11:00-23:00', $info['hours']['Monday'] );
		self::assertSame( '11:00-23:00', $info['hours']['Tuesday'] );
		self::assertSame( 'Closed', $info['hours']['Wednesday'] );

		// JSON-LD openingHoursSpecification derived from the same source —
		// one block per OPEN day (closed days are omitted from schema).
		self::assertCount( 2, $info['opening_hours'] );
		self::assertSame( 'OpeningHoursSpecification', $info['opening_hours'][0]['@type'] );
		self::assertSame( 'https://schema.org/Monday', $info['opening_hours'][0]['dayOfWeek'] );
		self::assertSame( '11:00', $info['opening_hours'][0]['opens'] );
		self::assertSame( '23:00', $info['opening_hours'][0]['closes'] );
	}

	public function test_resolver_display_store_overrides_schedule(): void {
		// Operator populated the dedicated display store for Monday only.
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_business_hours_mon' === $key ? '09:00-17:00' : $default;
			}
		);
		$this->stub_resolver_wp_functions( false );
		// A DIFFERENT schedule in the order-hours store must be ignored because
		// the display store is explicitly populated (deliberate override wins).
		\Lafka_Order_Hours::$lafka_order_hours_options = array(
			'lafka_order_hours_schedule' => self::build_schedule(
				array(
					0 => array( array( '11:00', '23:00' ) ),
					1 => array( array( '11:00', '23:00' ) ),
				)
			),
		);

		$info = lafka_get_restaurant_info();

		self::assertSame( '09:00-17:00', $info['hours']['Monday'] );
		// Schedule fallback did NOT fire: Tuesday (only in the schedule) absent.
		self::assertArrayNotHasKey( 'Tuesday', $info['hours'] );
	}

	public function test_resolver_hours_empty_when_neither_store_configured(): void {
		$this->stub_resolver_wp_functions();
		\Lafka_Order_Hours::$lafka_order_hours_options = array( 'lafka_order_hours_schedule' => '' );

		$info = lafka_get_restaurant_info();

		self::assertSame( array(), $info['hours'] );
		self::assertSame( array(), $info['opening_hours'] );
	}

	/**
	 * Stub the WP functions lafka_get_restaurant_info() touches.
	 *
	 * @param bool $stub_theme_mod Whether to also stub get_theme_mod to "unset"
	 *                             (skip when a test installs its own alias).
	 */
	private function stub_resolver_wp_functions( bool $stub_theme_mod = true ): void {
		Functions\when( 'get_option' )->returnArg( 2 );
		if ( $stub_theme_mod ) {
			Functions\when( 'get_theme_mod' )->returnArg( 2 );
		}
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost' );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
}
