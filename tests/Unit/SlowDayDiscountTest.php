<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-slow-day.php';

/**
 * Slow-day discount: percent + day-set resolvers, normalization, day matching.
 */
final class SlowDayDiscountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_normalize_days_from_csv(): void {
		self::assertSame( array( 1, 2, 3, 6 ), lafka_slow_day_normalize_days( '1,2,3,6' ) );
	}

	public function test_normalize_days_dedupes_sorts_and_bounds(): void {
		self::assertSame( array( 0, 2, 6 ), lafka_slow_day_normalize_days( array( 6, 2, 2, 0, 9, -1 ) ) );
		self::assertSame( array(), lafka_slow_day_normalize_days( '' ) );
	}

	public function test_active_dow_matching(): void {
		self::assertTrue( lafka_slow_day_is_active_dow( 2, array( 1, 2, 3, 6 ) ) ); // Tue
		self::assertFalse( lafka_slow_day_is_active_dow( 5, array( 1, 2, 3, 6 ) ) ); // Fri (busy)
	}

	public function test_percent_defaults_off_and_clamps(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => $d );
		self::assertSame( 0.0, lafka_slow_day_percent() );
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => 200 );
		self::assertSame( 100.0, lafka_slow_day_percent() );
	}

	public function test_eligible_only_when_percent_and_today_slow(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_slow_day_discount_percent' === $k ? 10 : ( 'lafka_slow_day_days' === $k ? '2' : $d )
		);
		Functions\when( 'current_time' )->justReturn( 2 ); // Tuesday → slow
		self::assertTrue( lafka_slow_day_eligible() );
		Functions\when( 'current_time' )->justReturn( 5 ); // Friday → not slow
		self::assertFalse( lafka_slow_day_eligible() );
	}
}
