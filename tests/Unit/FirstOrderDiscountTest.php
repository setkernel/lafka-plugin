<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-first-order.php';

/**
 * First-order discount: percent resolver, eligibility, and pure discount math.
 */
final class FirstOrderDiscountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_percent_defaults_off(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => $d );
		self::assertSame( 0.0, lafka_first_order_discount_percent() );
	}

	public function test_percent_clamped_0_100(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => 150 );
		self::assertSame( 100.0, lafka_first_order_discount_percent() );
	}

	public function test_discount_math(): void {
		self::assertSame( 0.0, lafka_first_order_discount_amount( 0.0, 15.0 ) );
		self::assertSame( 0.0, lafka_first_order_discount_amount( 40.0, 0.0 ) );
		self::assertSame( 6.0, lafka_first_order_discount_amount( 40.0, 15.0 ) );
		self::assertSame( 5.42, lafka_first_order_discount_amount( 36.13, 15.0 ) ); // AOV case
	}

	public function test_logged_out_never_eligible(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => 15 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		self::assertFalse( lafka_is_first_order_customer() );
		self::assertFalse( lafka_first_order_eligible() );
	}

	public function test_logged_in_first_timer_eligible(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => 15 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 0 );
		self::assertTrue( lafka_is_first_order_customer() );
		self::assertTrue( lafka_first_order_eligible() );
	}

	public function test_returning_customer_not_eligible(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => 15 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 3 );
		self::assertFalse( lafka_is_first_order_customer() );
	}
}
