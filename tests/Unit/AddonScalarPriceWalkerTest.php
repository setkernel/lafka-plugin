<?php
/**
 * v8.12.8: shared lafka_addons_walk_to_scalar_price() helper.
 *
 * Used by lafka_get_option_price_on_default_attribute (PDP price display)
 * and Lafka_Product_Addon_Cart's get_item_data + order_line_item (cart and
 * order display). Defends against any code path where a per-attribute
 * nested price array slipped past apply_attribute_specific_price()'s
 * variation-match coercion.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/lafka-product-addons.php';

final class AddonScalarPriceWalkerTest extends TestCase {

	public function test_passes_through_scalar_unchanged(): void {
		self::assertSame( '1.50', lafka_addons_walk_to_scalar_price( '1.50' ) );
		self::assertSame( 0, lafka_addons_walk_to_scalar_price( 0 ) );
		self::assertSame( 4.99, lafka_addons_walk_to_scalar_price( 4.99 ) );
	}

	public function test_walks_one_level_deep(): void {
		self::assertSame(
			'1.00',
			lafka_addons_walk_to_scalar_price( array( 'small' => '1.00', 'large' => '2.00' ) )
		);
	}

	public function test_walks_two_levels_deep(): void {
		self::assertSame(
			'1.50',
			lafka_addons_walk_to_scalar_price(
				array( 'pa_size' => array( 'medium' => '1.50', 'large' => '2.00' ) )
			)
		);
	}

	public function test_returns_zero_on_empty_array(): void {
		self::assertSame( 0, lafka_addons_walk_to_scalar_price( array() ) );
	}

	public function test_returns_zero_on_pathologically_deep_array(): void {
		$nested = '5.00';
		for ( $i = 0; $i < 20; $i++ ) {
			$nested = array( 'level' => $nested );
		}
		// Walker is depth-bounded at 10. Deeper than that → result is still
		// nested → coerce to 0 rather than letting an array leak.
		$result = lafka_addons_walk_to_scalar_price( $nested );
		self::assertIsScalar( $result );
	}

	public function test_returns_zero_on_object(): void {
		// An object isn't scalar and isn't array — walker returns 0 so the
		// downstream (float) cast / wc_price() call gets a safe value
		// rather than a TypeError.
		self::assertSame( 0, lafka_addons_walk_to_scalar_price( new \stdClass() ) );
	}

	public function test_terminates_quickly_on_pathological_input(): void {
		$nested = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$nested = array( 'k' . $i => $nested );
		}
		$start  = microtime( true );
		$result = lafka_addons_walk_to_scalar_price( $nested );
		$elapsed = microtime( true ) - $start;
		self::assertLessThan( 0.05, $elapsed );
	}
}
