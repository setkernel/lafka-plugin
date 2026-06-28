<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-first-order.php';
require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-slow-day.php';
require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-combo-deal.php';

/**
 * Regression coverage for the order-level discount coordinator.
 *
 * Guards the f002 defect: first-order + slow-day + combo discounts used to each
 * add a negative fee off the raw pre-coupon subtotal with only an individual
 * cap, so they stacked additively (e.g. 60% + 60% = -120% → WooCommerce clamps
 * the total to $0 → free order). The coordinator now applies percentages
 * sequentially to a diminishing balance and clamps the single combined fee so it
 * can never exceed the payable base (subtotal minus coupons already applied).
 */
final class OrderDiscountCoordinatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- Pure aggregation math -------------------------------------------

	public function test_stacked_percents_compound_not_add(): void {
		// The brief's example: first-order 60% + slow-day 60% on a $100 cart.
		// Additive (the bug) would be 120% → free. Sequential compounding:
		// 100 → 40 → 16, so the discount is 84 and $16 is still payable.
		$discount = lafka_order_discount_combined( 100.0, array( 60.0, 60.0 ) );
		self::assertSame( 84.0, $discount );
		self::assertGreaterThan( 0.0, 100.0 - $discount, 'a non-zero subtotal must never be discounted to free' );
	}

	public function test_all_three_promos_leave_a_positive_balance(): void {
		// first-order 60% + slow-day 60% + combo 50% on $100:
		// 100 → 40 → 16 → 8. Discount 92, $8 still owed.
		$discount = lafka_order_discount_combined( 100.0, array( 60.0, 60.0, 50.0 ) );
		self::assertSame( 92.0, $discount );
		self::assertGreaterThan( 0.0, 100.0 - $discount );
	}

	public function test_fixed_combo_is_capped_to_remaining_balance(): void {
		// 50% then a $100 fixed combo on a $40 cart: 40 → 20, fixed takes the
		// remaining 20 → discount equals subtotal (total 0), never below zero.
		$discount = lafka_order_discount_combined( 40.0, array( 50.0 ), 100.0 );
		self::assertSame( 40.0, $discount );
		self::assertGreaterThanOrEqual( 0.0, 40.0 - $discount );
	}

	public function test_coupon_already_applied_caps_the_promo_discount(): void {
		// $100 cart, an $80 coupon already applied, first-order 60%.
		// Uncapped the promo would take another $60 → total -$40 → free.
		// The payable cap is 100 - 80 = 20, so the promo fee is clamped to 20.
		$discount = lafka_order_discount_combined( 100.0, array( 60.0 ), 0.0, 80.0 );
		self::assertSame( 20.0, $discount );
		self::assertGreaterThanOrEqual( 0.0, 100.0 - 80.0 - $discount, 'coupon + promo must not drive the total negative' );
	}

	public function test_zero_subtotal_yields_no_discount(): void {
		self::assertSame( 0.0, lafka_order_discount_combined( 0.0, array( 60.0, 60.0 ), 100.0 ) );
	}

	public function test_no_components_yields_no_discount(): void {
		self::assertSame( 0.0, lafka_order_discount_combined( 100.0, array(), 0.0 ) );
	}

	/**
	 * Invariant across a matrix of plausible operator configurations: the
	 * combined discount never exceeds the subtotal, and the resulting total is
	 * never negative (never silently clamped to free by WooCommerce).
	 *
	 * @param float   $subtotal
	 * @param float[] $percents
	 * @param float   $fixed
	 * @param float   $coupon
	 */
	#[DataProvider('comboMatrixProvider')]
	public function test_combined_never_exceeds_payable_base( float $subtotal, array $percents, float $fixed, float $coupon ): void {
		$discount = lafka_order_discount_combined( $subtotal, $percents, $fixed, $coupon );
		self::assertGreaterThanOrEqual( 0.0, $discount );
		self::assertLessThanOrEqual( $subtotal, $discount, 'discount must never exceed the subtotal' );
		// Final payable = subtotal - coupons already applied - our combined fee.
		self::assertGreaterThanOrEqual( 0.0, round( $subtotal - $coupon - $discount, 2 ), 'total must never go negative' );
	}

	public static function comboMatrixProvider(): array {
		return array(
			'dual 30%'              => array( 80.0, array( 30.0, 30.0 ), 0.0, 0.0 ),
			'dual 60%'             => array( 100.0, array( 60.0, 60.0 ), 0.0, 0.0 ),
			'triple percent'       => array( 36.13, array( 60.0, 60.0, 50.0 ), 0.0, 0.0 ),
			'percent + fixed'      => array( 50.0, array( 40.0 ), 25.0, 0.0 ),
			'over-100 summed'      => array( 100.0, array( 100.0, 100.0 ), 0.0, 0.0 ),
			'huge fixed'           => array( 20.0, array(), 999.0, 0.0 ),
			'percent + big coupon' => array( 100.0, array( 60.0 ), 0.0, 90.0 ),
			'all + coupon'         => array( 75.5, array( 50.0, 25.0 ), 10.0, 30.0 ),
		);
	}

	// ---- Coordinator wiring (providers feed one capped fee) ---------------

	public function test_apply_adds_single_capped_fee_for_three_stacked_promos(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		// Simulate first-order + slow-day + combo each feeding the components filter.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'lafka_order_discount_components' === $hook ) {
					return array(
						array( 'type' => 'percent', 'value' => 60.0, 'label' => 'First-order discount' ),
						array( 'type' => 'percent', 'value' => 60.0, 'label' => 'Slow-day special' ),
						array( 'type' => 'percent', 'value' => 50.0, 'label' => 'Combo deal' ),
					);
				}
				return $value;
			}
		);

		$cart = self::makeCart( 100.0, 0.0 );
		lafka_order_discount_apply( $cart );

		self::assertCount( 1, $cart->fees, 'exactly one combined fee must be added' );
		self::assertSame( -92.0, $cart->fees[0]['amount'] );
		self::assertGreaterThan( 0.0, 100.0 + $cart->fees[0]['amount'], 'stacked promos must not make the order free' );
		self::assertSame( 'First-order discount + Slow-day special + Combo deal', $cart->fees[0]['name'] );
	}

	public function test_apply_clamps_combined_fee_to_subtotal(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'lafka_order_discount_components' === $hook ) {
					// A fixed combo larger than the cart plus a percent promo.
					return array(
						array( 'type' => 'percent', 'value' => 50.0, 'label' => 'Slow-day special' ),
						array( 'type' => 'fixed', 'value' => 999.0, 'label' => 'Combo deal' ),
					);
				}
				return $value;
			}
		);

		$cart = self::makeCart( 40.0, 0.0 );
		lafka_order_discount_apply( $cart );

		self::assertCount( 1, $cart->fees );
		self::assertLessThanOrEqual( 40.0, abs( $cart->fees[0]['amount'] ), 'fee must never exceed the subtotal' );
		self::assertGreaterThanOrEqual( 0.0, 40.0 + $cart->fees[0]['amount'], 'total must never go negative' );
	}

	public function test_apply_accounts_for_existing_coupon_discount(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'lafka_order_discount_components' === $hook ) {
					return array( array( 'type' => 'percent', 'value' => 60.0, 'label' => 'First-order discount' ) );
				}
				return $value;
			}
		);

		// $100 cart with an $80 coupon already applied → only $20 may be discounted.
		$cart = self::makeCart( 100.0, 80.0 );
		lafka_order_discount_apply( $cart );

		self::assertCount( 1, $cart->fees );
		self::assertSame( -20.0, $cart->fees[0]['amount'] );
		self::assertGreaterThanOrEqual( 0.0, 100.0 - 80.0 + $cart->fees[0]['amount'] );
	}

	public function test_apply_adds_no_fee_when_no_components(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value = null ) => 'lafka_order_discount_components' === $hook ? array() : $value
		);

		$cart = self::makeCart( 100.0, 0.0 );
		lafka_order_discount_apply( $cart );
		self::assertCount( 0, $cart->fees );
	}

	// ---- Providers genuinely contribute to the coordinator ---------------

	public function test_first_order_and_slow_day_providers_feed_percent_components(): void {
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => $d );
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $k, $d = false ) {
				switch ( $k ) {
					case 'lafka_first_order_discount_percent':
						return 60;
					case 'lafka_slow_day_discount_percent':
						return 60;
					case 'lafka_slow_day_days':
						return '2';
					default:
						return $d;
				}
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 0 );
		Functions\when( 'current_time' )->justReturn( 2 ); // Tuesday → slow day

		$components = lafka_first_order_discount_component( array() );
		$components = lafka_slow_day_discount_component( $components );

		self::assertCount( 2, $components );
		self::assertSame( 'percent', $components[0]['type'] );
		self::assertSame( 'percent', $components[1]['type'] );
		self::assertSame( 60.0, (float) $components[0]['value'] );
		self::assertSame( 60.0, (float) $components[1]['value'] );

		// Fed through the coordinator math, the two 60% promos compound to 84.
		$discount = lafka_order_discount_combined(
			100.0,
			array( (float) $components[0]['value'], (float) $components[1]['value'] )
		);
		self::assertSame( 84.0, $discount );
		self::assertGreaterThan( 0.0, 100.0 - $discount );
	}

	/**
	 * Minimal WC_Cart double capturing add_fee() calls.
	 *
	 * @param float $subtotal
	 * @param float $coupon_discount
	 * @return object
	 */
	private static function makeCart( float $subtotal, float $coupon_discount ): object {
		return new class( $subtotal, $coupon_discount ) {
			/** @var array<int,array<string,mixed>> */
			public array $fees = array();
			public function __construct( private float $subtotal, private float $coupon_discount ) {}
			public function get_subtotal(): float {
				return $this->subtotal;
			}
			public function get_discount_total(): float {
				return $this->coupon_discount;
			}
			public function add_fee( $name, $amount, $taxable = false ): void {
				$this->fees[] = array(
					'name'    => $name,
					'amount'  => $amount,
					'taxable' => $taxable,
				);
			}
		};
	}
}
