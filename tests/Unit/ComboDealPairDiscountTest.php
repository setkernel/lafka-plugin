<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-combo-deal.php';

/**
 * Regression coverage for f068.
 *
 * A percent combo used to contribute a 'percent' component, which the order
 * discount coordinator applies against the WHOLE cart subtotal — so a 20% combo
 * on a cart of many items took 20% off every item once a single A+B pair
 * existed. The combo is a category-PAIR deal, so the discount must be based on
 * the qualifying pair only (cheapest item in each category, one unit apiece).
 */
final class ComboDealPairDiscountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- Pure pair finding ---------------------------------------------------

	public function test_find_pair_returns_cheapest_one_unit_of_each_category(): void {
		$items = array(
			'pizza_a' => array( 'cats' => array( 10 ), 'price' => 14.0 ),
			'pizza_b' => array( 'cats' => array( 10 ), 'price' => 10.0 ), // cheapest A
			'poutine' => array( 'cats' => array( 20 ), 'price' => 6.0 ),  // only B
			'salad'   => array( 'cats' => array( 30 ), 'price' => 9.0 ),  // unrelated
		);
		$pair = lafka_combo_find_pair( $items, 10, 20 );
		self::assertSame( 'pizza_b', $pair['a'] );
		self::assertSame( 'poutine', $pair['b'] );
		self::assertSame( 16.0, $pair['subtotal'], 'pair subtotal is the cheapest A unit + cheapest B unit' );
	}

	public function test_find_pair_requires_two_different_line_items(): void {
		// Only pizzas, no cat-B item → no pair.
		$items = array(
			array( 'cats' => array( 10 ), 'price' => 10.0 ),
			array( 'cats' => array( 10 ), 'price' => 12.0 ),
		);
		self::assertSame( array(), lafka_combo_find_pair( $items, 10, 20 ) );
	}

	public function test_find_pair_single_item_in_both_cats_does_not_self_qualify(): void {
		$items = array(
			array( 'cats' => array( 10, 20 ), 'price' => 18.0 ), // one product in both cats
		);
		self::assertSame( array(), lafka_combo_find_pair( $items, 10, 20 ) );

		// The same product + a separate poutine does qualify.
		$items[] = array( 'cats' => array( 20 ), 'price' => 6.0 );
		$pair    = lafka_combo_find_pair( $items, 10, 20 );
		self::assertSame( 24.0, $pair['subtotal'] );
	}

	public function test_find_pair_zero_category_never_matches(): void {
		$items = array(
			array( 'cats' => array( 10 ), 'price' => 10.0 ),
			array( 'cats' => array( 20 ), 'price' => 6.0 ),
		);
		self::assertSame( array(), lafka_combo_find_pair( $items, 0, 20 ) );
	}

	// ---- Component: discount is based on the pair, not the whole cart ---------

	public function test_percent_combo_discounts_only_the_pair_not_the_cart(): void {
		// Cart: 5 pizzas @ $10 + 1 poutine @ $6 + 1 salad @ $9 → cart subtotal $65.
		// The qualifying pair is one pizza ($10) + one poutine ($6) = $16.
		$this->configureCombo( 10, 20, 20.0, 'percent' );
		$this->mockCart(
			array(
				array( 'product_id' => 101, 'quantity' => 5, 'line_subtotal' => 50.0, 'cats' => array( 10 ) ),
				array( 'product_id' => 102, 'quantity' => 1, 'line_subtotal' => 6.0, 'cats' => array( 20 ) ),
				array( 'product_id' => 103, 'quantity' => 1, 'line_subtotal' => 9.0, 'cats' => array( 30 ) ),
			)
		);

		$components = lafka_combo_deal_component( array() );

		self::assertCount( 1, $components );
		$component = $components[0];
		self::assertSame( 'combo_deal', $component['source'] );
		self::assertSame( 'fixed', $component['type'], 'the pair discount is contributed as a concrete dollar amount' );
		// 20% of the $16 pair, NOT 20% of the $65 cart.
		self::assertSame( 3.2, $component['value'] );
		self::assertNotSame( 13.0, $component['value'], 'must not discount the whole cart subtotal' );
	}

	public function test_fixed_combo_is_capped_at_the_pair_subtotal(): void {
		// A $30 fixed combo on a $16 pair must be capped at $16, even though the
		// cart subtotal is far larger.
		$this->configureCombo( 10, 20, 30.0, 'fixed' );
		$this->mockCart(
			array(
				array( 'product_id' => 101, 'quantity' => 5, 'line_subtotal' => 50.0, 'cats' => array( 10 ) ),
				array( 'product_id' => 102, 'quantity' => 1, 'line_subtotal' => 6.0, 'cats' => array( 20 ) ),
			)
		);

		$components = lafka_combo_deal_component( array() );

		self::assertCount( 1, $components );
		self::assertSame( 'fixed', $components[0]['type'] );
		self::assertSame( 16.0, $components[0]['value'], 'fixed combo is capped at the pair subtotal' );
	}

	public function test_no_component_when_cart_has_no_pair(): void {
		$this->configureCombo( 10, 20, 20.0, 'percent' );
		$this->mockCart(
			array(
				array( 'product_id' => 101, 'quantity' => 3, 'line_subtotal' => 30.0, 'cats' => array( 10 ) ),
			)
		);

		self::assertSame( array(), lafka_combo_deal_component( array() ) );
	}

	// ---- Helpers -------------------------------------------------------------

	/**
	 * Stub the combo configuration via get_option / get_theme_mod.
	 *
	 * @param int    $cat_a
	 * @param int    $cat_b
	 * @param float  $amount
	 * @param string $type
	 */
	private function configureCombo( int $cat_a, int $cat_b, float $amount, string $type ): void {
		$map = array(
			'lafka_combo_deal_cat_a'  => (string) $cat_a,
			'lafka_combo_deal_cat_b'  => (string) $cat_b,
			'lafka_combo_deal_amount' => (string) $amount,
			'lafka_combo_deal_type'   => $type,
		);
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) => $map[ $key ] ?? ''
		);
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = false ) => $default
		);
	}

	/**
	 * Stub WC()->cart->get_cart() with the given line items and
	 * wc_get_product_term_ids() with each item's category map.
	 *
	 * @param array<int,array{product_id:int,quantity:int,line_subtotal:float,cats:int[]}> $items
	 */
	private function mockCart( array $items ): void {
		$cart_items = array();
		$cats_by_id = array();
		foreach ( $items as $i => $spec ) {
			$cart_items[ 'item_' . $i ] = array(
				'product_id'    => $spec['product_id'],
				'quantity'      => $spec['quantity'],
				'line_subtotal' => $spec['line_subtotal'],
			);
			$cats_by_id[ $spec['product_id'] ] = $spec['cats'];
		}

		$cart = new class( $cart_items ) {
			/** @param array<string,mixed> $cart_items */
			public function __construct( private array $cart_items ) {}
			/** @return array<string,mixed> */
			public function get_cart(): array {
				return $this->cart_items;
			}
		};

		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
		Functions\when( 'wc_get_product_term_ids' )->alias(
			static fn( $pid, $taxonomy = 'product_cat' ) => $cats_by_id[ (int) $pid ] ?? array()
		);
	}
}
