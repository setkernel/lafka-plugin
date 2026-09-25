<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTime;
use DateTimeZone;
use Lafka_Order_Hours;
use Lafka_Shipping_Areas_Admin;
use Lafka_Timeslots;
use LafkaPlugin\Tests\Unit\Support\StableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Support/StableClock.php';

/**
 * Regression lock for the timeslot-duration floor (audit f048).
 *
 * A stored slot duration of 0 / '' made the public (wp_ajax_nopriv) time-slot
 * endpoint loop forever or fatal, so an anonymous visitor could hang a PHP
 * worker. The duration is now clamped when saved and floored wherever it is
 * read or consumed.
 */
final class TimeslotDurationFloorTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'esc_html__' )->returnArg();
		require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/includes/class-lafka-shipping-areas-admin.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonPositiveDurationProvider(): array {
		return array(
			'zero int'     => array( 0 ),
			'zero string'  => array( '0' ),
			'empty string' => array( '' ),
			'negative int' => array( -15 ),
			'non-numeric'  => array( 'abc' ),
		);
	}

	#[DataProvider( 'nonPositiveDurationProvider' )]
	public function test_get_timeslots_for_date_returns_empty_on_bad_duration( $duration ): void {
		$date = new DateTime( '2026-07-01', new DateTimeZone( 'UTC' ) );

		$this->assertSame( array(), Lafka_Timeslots::get_timeslots_for_date( $date, $duration ) );
	}

	public function test_enabled_dates_survive_an_unusable_duration(): void {
		// Today's "any slots left?" check builds a DateInterval from the
		// duration; an empty one must hide today rather than fatal.
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
		Lafka_Order_Hours::$timezone                            = '';
		Lafka_Order_Hours::$lafka_order_hours_holidays_calendar = '';
		Lafka_Order_Hours::$lafka_order_hours_schedule          = (string) json_encode(
			array_fill(
				0,
				7,
				array(
					'periods' => array(
						array(
							'start' => '00:00',
							'end'   => '00:00',
						),
					),
				)
			)
		);

		try {
			// The store clock is the real one: pin "tomorrow" to the day the
			// call saw, so a run straddling midnight cannot disagree with it.
			list( $dates, $tomorrow ) = StableClock::run(
				static fn(): string => ( new DateTime( 'tomorrow', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' ),
				static fn(): array => Lafka_Timeslots::get_enabled_dates_for_days_ahead( 1, '' )
			);
		} finally {
			Lafka_Order_Hours::$lafka_order_hours_schedule = null;
		}

		$this->assertSame( array( $tomorrow ), $dates );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function durationClampProvider(): array {
		return array(
			'zero clamps to 1'       => array( array( 'timeslot_duration' => '0' ), 1 ),
			'negative clamps to 1'   => array( array( 'timeslot_duration' => '-30' ), 1 ),
			'empty falls back to 60' => array( array( 'timeslot_duration' => '' ), 60 ),
			'missing falls back 60'  => array( array(), 60 ),
			'over max clamps to 720' => array( array( 'timeslot_duration' => '5000' ), 720 ),
			'in range preserved'     => array( array( 'timeslot_duration' => '45' ), 45 ),
			'float floored'          => array( array( 'timeslot_duration' => '90.7' ), 90 ),
		);
	}

	#[DataProvider( 'durationClampProvider' )]
	public function test_sanitize_clamps_timeslot_duration( array $input, int $expected ): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings( $input );

		$this->assertSame( $expected, $out['timeslot_duration'] );
	}

	public function test_sanitize_floors_days_ahead_and_orders_per_timeslot(): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings(
			array(
				'days_ahead'          => '-5',
				'orders_per_timeslot' => '0',
			)
		);

		$this->assertSame( 0, $out['days_ahead'], 'days_ahead must floor at 0.' );
		$this->assertSame( 1, $out['orders_per_timeslot'], 'A set orders_per_timeslot must floor at 1.' );

		$capped = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings(
			array(
				'days_ahead'          => '9999',
				'orders_per_timeslot' => '99999',
			)
		);
		$this->assertSame( 365, $capped['days_ahead'], 'days_ahead must cap at 365.' );
		$this->assertSame( 1000, $capped['orders_per_timeslot'], 'orders_per_timeslot must cap at 1000.' );
	}

	public function test_sanitize_treats_empty_orders_per_timeslot_as_no_cap(): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings( array( 'orders_per_timeslot' => '' ) );

		// Empty means "no cap"; forcing it to 1 would cap every slot at one order.
		$this->assertEmpty( $out['orders_per_timeslot'] ?? '' );
	}

	public function test_sanitize_preserves_checkboxes_and_unknown_keys(): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings(
			array(
				'enable_datetime_option' => '1',
				'datetime_mandatory'     => '1',
				'some_future_key'        => 'keepme',
			)
		);

		$this->assertSame( '1', $out['enable_datetime_option'] );
		$this->assertSame( '1', $out['datetime_mandatory'] );
		$this->assertSame( 'keepme', $out['some_future_key'] );
	}

	public function test_saving_the_datetime_settings_runs_the_sanitizer(): void {
		$settings = array();
		Functions\when( 'register_setting' )->alias(
			static function ( $group, $name, $args = array() ) use ( &$settings ) {
				$settings[ $name ] = $args;
			}
		);
		Functions\when( 'add_settings_section' )->justReturn( null );
		Functions\when( 'add_settings_field' )->justReturn( null );

		Lafka_Shipping_Areas_Admin::admin_init();

		$sanitize = $settings['lafka_shipping_areas_datetime']['sanitize_callback'] ?? null;
		$this->assertIsCallable( $sanitize, 'Without a sanitize_callback the field min/max is HTML-only.' );
		$this->assertSame( 1, $sanitize( array( 'timeslot_duration' => '0' ) )['timeslot_duration'] );
	}

	public function test_loaded_duration_never_drops_below_one_minute(): void {
		$read = static function (): int {
			$timeslots = ( new ReflectionClass( Lafka_Timeslots::class ) )->newInstanceWithoutConstructor();
			$timeslots->init_order_date_time_options();
			return $timeslots->get_timeslot_duration();
		};
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '1',
			'timeslot_duration'      => '0',
		);
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

		$this->assertSame( 1, $read(), 'Global duration of 0.' );

		// A branch that overrides the global date/time settings with an empty duration.
		$session = new class() {
			public function get( $key ) {
				return 'lafka_branch_location' === $key ? array( 'branch_id' => 7 ) : null;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
		Functions\when( 'get_term_meta' )->alias(
			static fn( $term_id, $key ) => 'lafka_branch_override_datetime_global' === $key ? '1' : ''
		);

		$this->assertSame( 1, $read(), 'Branch override with an empty duration.' );
	}
}
