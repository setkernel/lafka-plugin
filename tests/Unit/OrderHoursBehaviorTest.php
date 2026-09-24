<?php
/**
 * Behavioural coverage for Lafka_Order_Hours' clock handling.
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
use PHPUnit\Framework\TestCase;

final class OrderHoursBehaviorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
		Lafka_Order_Hours::$timezone                                = '';
		Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
		Lafka_Order_Hours::$lafka_order_hours_holidays_calendar     = '';
	}

	protected function tearDown(): void {
		Lafka_Order_Hours::$lafka_order_hours_schedule = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_next_opening_is_computed_on_the_store_clock(): void {
		// Opens for one minute at midnight every day, so "now" is closed and the
		// next opening is tomorrow 00:00 — on the store's clock, not UTC's.
		$period   = array(
			'periods' => array(
				array(
					'start' => '00:00',
					'end'   => '00:01',
				),
			),
		);
		$schedule = (string) json_encode( array_fill( 0, 7, $period ) );
		$tz       = new DateTimeZone( 'Pacific/Kiritimati' ); // UTC+14.
		Functions\when( 'wp_timezone' )->justReturn( $tz );
		Lafka_Order_Hours::$lafka_order_hours_schedule = $schedule;

		$next = Lafka_Order_Hours::get_next_opening_time_by_params( null, $schedule, null, null, null );

		$this->assertInstanceOf( DateTime::class, $next );
		$this->assertSame( ( new DateTime( 'tomorrow', $tz ) )->getTimestamp(), $next->getTimestamp() );
	}
}
