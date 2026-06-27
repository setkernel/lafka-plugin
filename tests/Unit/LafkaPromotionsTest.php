<?php
/**
 * Plugin-side behavior lock for BOGO + delivery-min math (P2-01b).
 *
 * Mirrors the child-side LafkaPromotionsTest. Asserts against the static
 * methods on Lafka_Promotions so any drift between the plugin's copy of
 * the math and the child's pure helpers is caught in CI.
 *
 * Class skips its WP-runtime hook registration when add_action() isn't
 * defined — so we can require the file standalone and call statics.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Promotions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';

final class LafkaPromotionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// knob() reads via get_option() with an internal static cache. Stub
		// get_option to return empty so knob() falls back to the constants.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── distribute_discounts (audit case list) ─────────────────────────────

	public function test_zero_units_returns_no_discounts(): void {
		self::assertSame( array(), Lafka_Promotions::distribute_discounts( array() ) );
	}

	public function test_one_unit_returns_no_discounts(): void {
		$units = array( array( 'key' => 'A', 'price' => 10.0 ) );
		self::assertSame( array(), Lafka_Promotions::distribute_discounts( $units ) );
	}

	public function test_two_units_one_discounted_on_cheaper(): void {
		$units = array(
			array( 'key' => 'B', 'price' => 10.0 ),
			array( 'key' => 'A', 'price' => 5.0 ),
		);
		self::assertSame( array( 'A' => 1 ), Lafka_Promotions::distribute_discounts( $units ) );
	}

	public function test_three_units_one_discounted(): void {
		$units = array(
			array( 'key' => 'A', 'price' => 3.0 ),
			array( 'key' => 'B', 'price' => 5.0 ),
			array( 'key' => 'C', 'price' => 10.0 ),
		);
		self::assertSame( array( 'A' => 1 ), Lafka_Promotions::distribute_discounts( $units ) );
	}

	public function test_four_units_two_discounted(): void {
		$units = array(
			array( 'key' => 'A', 'price' => 3.0 ),
			array( 'key' => 'A', 'price' => 3.0 ),
			array( 'key' => 'B', 'price' => 5.0 ),
			array( 'key' => 'C', 'price' => 10.0 ),
		);
		self::assertSame( array( 'A' => 2 ), Lafka_Promotions::distribute_discounts( $units ) );
	}

	public function test_six_units_three_discounted_across_keys(): void {
		$units = array(
			array( 'key' => 'A', 'price' => 3.0 ),
			array( 'key' => 'A', 'price' => 3.0 ),
			array( 'key' => 'B', 'price' => 5.0 ),
			array( 'key' => 'B', 'price' => 5.0 ),
			array( 'key' => 'C', 'price' => 10.0 ),
			array( 'key' => 'C', 'price' => 10.0 ),
		);
		self::assertSame( array( 'A' => 2, 'B' => 1 ), Lafka_Promotions::distribute_discounts( $units ) );
	}

	// ─── blended_price ──────────────────────────────────────────────────────

	public function test_blended_returns_orig_when_zero_discounted(): void {
		self::assertSame( 10.0, Lafka_Promotions::blended_price( 10.0, 3, 0 ) );
	}

	public function test_blended_two_units_one_discounted(): void {
		// 1 full @ $10 + 1 half @ $5 = $15 / 2 = $7.50/unit (BOGO_DISCOUNT=0.5)
		self::assertSame( 7.5, Lafka_Promotions::blended_price( 10.0, 2, 1 ) );
	}

	public function test_blended_four_units_two_discounted(): void {
		self::assertSame( 7.5, Lafka_Promotions::blended_price( 10.0, 4, 2 ) );
	}

	// ─── should_block_delivery boundary ─────────────────────────────────────

	public function test_delivery_blocked_just_below_threshold(): void {
		self::assertTrue( Lafka_Promotions::should_block_delivery( 29.99 ) );
	}

	public function test_delivery_allowed_exactly_at_threshold(): void {
		self::assertFalse( Lafka_Promotions::should_block_delivery( 30.00 ) );
	}

	public function test_delivery_allowed_above_threshold(): void {
		self::assertFalse( Lafka_Promotions::should_block_delivery( 30.01 ) );
	}

	// ─── knob() resolution ─────────────────────────────────────────────────

	public function test_knob_falls_back_to_constants_when_option_empty(): void {
		self::assertSame( Lafka_Promotions::DELIVERY_MIN, Lafka_Promotions::knob( 'delivery_min' ) );
		self::assertSame( Lafka_Promotions::BOGO_DISCOUNT, Lafka_Promotions::knob( 'bogo_discount' ) );
		self::assertSame( Lafka_Promotions::PROMO_KEY, Lafka_Promotions::knob( 'promo_key' ) );
		self::assertSame( Lafka_Promotions::DISMISS_DAYS, Lafka_Promotions::knob( 'dismiss_days' ) );
	}
}
