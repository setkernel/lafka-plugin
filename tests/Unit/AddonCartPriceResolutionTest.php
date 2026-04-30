<?php
/**
 * v8.12.6: cart-side per-attribute addon price resolution.
 *
 * Locks in the safety nets in apply_attribute_specific_price():
 *   1. Matched variation → uses the nested price (happy path).
 *   2. Unmatched variation → walks the array down to a scalar so naive
 *      `(float) $array` casts don't silently bill customers $1.00 per addon.
 *   3. Multi-attribute matrix → first match wins, deterministic.
 *   4. Already-scalar input → unchanged.
 *
 * Background: a customer-billing bug. When a variable product had a per-
 * attribute addon (e.g. "Extra cheese" priced per pizza size) and the
 * customer's variation didn't match the addon's expected attribute key
 * (deleted attribute term, simple-product fallback, partial regression),
 * the code left $addon['price'] as a nested array. Downstream, line 64 of
 * add_cart_item() does `$price += (float) $addon['price']`, and PHP casts
 * a non-empty array to integer 1 — silently overcharging $1.00 per addon
 * with no error or notice.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Product_Addon_Cart;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/includes/class-lafka-product-addon-cart.php';

final class AddonCartPriceResolutionTest extends TestCase {

	private Lafka_Product_Addon_Cart $cart;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// add_action/add_filter are pre-stubbed by tests/bootstrap.php as no-ops;
		// don't redefine them via Brain Monkey or Patchwork raises
		// DefinedTooEarly. Anything cart-internal that needs stubs goes here.
		$this->cart = new Lafka_Product_Addon_Cart();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_matched_variation_resolves_to_scalar_price(): void {
		$addons    = array(
			array(
				'name'  => 'Extra cheese',
				'price' => array(
					'pa_size' => array(
						'small'  => '1.00',
						'medium' => '1.50',
						'large'  => '2.00',
					),
				),
			),
		);
		$cart_item = array(
			'variation' => array( 'attribute_pa_size' => 'medium' ),
		);

		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

		self::assertSame( '1.50', $result[0]['price'] );
	}

	/**
	 * The CUSTOMER-BILLING regression: when the variation doesn't match the
	 * addon's nested price key, the array used to leak through to
	 * `(float) $addon['price']` which casts to 1.0 → $1.00 phantom surcharge
	 * per addon. Test that we now walk down to a scalar instead.
	 */
	public function test_unmatched_variation_walks_array_to_scalar_not_cast_to_one(): void {
		$addons    = array(
			array(
				'name'  => 'Extra cheese',
				'price' => array(
					'pa_size' => array(
						'small' => '1.00',
					),
				),
			),
		);
		$cart_item = array(
			// Customer chose Crust, no Size in the variation. Old code: array
			// stays an array → casts to 1.0 → $1.00 phantom surcharge.
			'variation' => array( 'attribute_pa_crust' => 'thin' ),
		);

		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

		self::assertIsScalar( $result[0]['price'], 'Price must collapse to a scalar to prevent (float)array=1 surcharge.' );
		self::assertSame( '1.00', $result[0]['price'], 'Walks to deepest scalar — first leaf in the matrix.' );
	}

	public function test_no_variation_data_walks_to_scalar(): void {
		$addons    = array(
			array(
				'name'  => 'Extra cheese',
				'price' => array(
					'pa_size' => array( 'medium' => '1.50' ),
				),
			),
		);
		$cart_item = array(); // no 'variation' key at all

		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

		self::assertIsScalar( $result[0]['price'] );
		self::assertSame( '1.50', $result[0]['price'] );
	}

	public function test_already_scalar_price_passes_through_unchanged(): void {
		$addons    = array(
			array(
				'name'  => 'Extra cheese',
				'price' => '2.50',
			),
		);
		$cart_item = array(
			'variation' => array( 'attribute_pa_size' => 'medium' ),
		);

		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

		self::assertSame( '2.50', $result[0]['price'] );
	}

	/**
	 * Multi-attribute matrix: when both Size and Crust have nested prices and
	 * the cart item has both, the first match wins. Without a `break`, the
	 * loop was last-write-wins and order-dependent → non-deterministic price.
	 */
	public function test_multi_attribute_first_match_wins_deterministic(): void {
		$addons    = array(
			array(
				'name'  => 'Extra cheese',
				'price' => array(
					'pa_size'  => array( 'medium' => '1.50' ),
					'pa_crust' => array( 'thin' => '99.99' ),
				),
			),
		);
		$cart_item = array(
			'variation' => array(
				'attribute_pa_size'  => 'medium',
				'attribute_pa_crust' => 'thin',
			),
		);

		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

		self::assertSame( '1.50', $result[0]['price'], 'First match (pa_size, in $_POST order) must win — not last.' );
	}

	public function test_walk_is_depth_bounded_against_corrupt_data(): void {
		// Pathological self-referencing-ish data. The walker has a depth
		// limit; even if the array is unusually deep, we must terminate
		// with a scalar (or 0) rather than infinite-looping.
		$nested = '5.00';
		for ( $i = 0; $i < 15; $i++ ) {
			$nested = array( 'level' . $i => $nested );
		}

		$addons    = array(
			array( 'name' => 'Pathological', 'price' => $nested ),
		);
		$cart_item = array(); // unmatched

		$start  = microtime( true );
		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );
		$elapsed = microtime( true ) - $start;

		self::assertLessThan( 0.1, $elapsed, 'Walker must terminate quickly — depth-bounded.' );
		// At depth limit (10), result is still nested — coerce to 0 in that
		// case rather than letting an array leak to (float) cast.
		self::assertIsScalar( $result[0]['price'] );
	}

	public function test_addon_without_price_key_is_skipped_safely(): void {
		$addons    = array(
			array( 'name' => 'No price addon' ), // no 'price' key
		);
		$cart_item = array( 'variation' => array( 'attribute_pa_size' => 'medium' ) );

		$result = $this->cart->apply_attribute_specific_price( $addons, $cart_item );

		self::assertSame( $addons, $result, 'Addons without a price key pass through unchanged.' );
	}
}
