<?php
/**
 * v8.12.5: behavioral tests for the addon attribute resolver and the
 * defensive nested-price merge.
 *
 * These exercise the actual methods (not just source-grep) because v8.12.4
 * shipped with a strict `array $addon` typehint on resolve_addon_attribute_values
 * that fataled the moment WordPress ran admin_enqueue_scripts on the addons
 * page — styles() includes the option-row template to build a "new option"
 * preview without $addon in scope, so the resolver received null and the
 * strict typehint blew up the whole page.
 *
 * The lesson: source-grep tests verify shape, not behavior. Pair them with
 * a behavioral test that calls the method on the unhappy paths (null, scalar,
 * malformed array) so the next strict-typing or array-access regression
 * fails in CI instead of in production.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Product_Addon_Admin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/admin/class-lafka-product-addon-admin.php';

final class AddonAttributeResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default no-op stubs for WC functions the resolver may call.
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->justReturn( '' );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ===== resolve_addon_attribute_values ===================================== */

	/**
	 * The original v8.12.4 fatal: admin_enqueue_scripts → styles() → include
	 * html-addon-option.php with no $addon → resolver called with null →
	 * strict typehint TypeError → "critical error on this website".
	 */
	public function test_resolver_returns_empty_on_null(): void {
		self::assertSame( array(), Lafka_Product_Addon_Admin::resolve_addon_attribute_values( null ) );
	}

	public function test_resolver_returns_empty_on_string(): void {
		self::assertSame( array(), Lafka_Product_Addon_Admin::resolve_addon_attribute_values( 'unexpected' ) );
	}

	public function test_resolver_returns_empty_on_int(): void {
		self::assertSame( array(), Lafka_Product_Addon_Admin::resolve_addon_attribute_values( 0 ) );
	}

	public function test_resolver_returns_empty_when_variations_disabled(): void {
		$addon = array(
			'variations' => 0,
			'attribute'  => 5,
			'options'    => array( array( 'price' => array( 'pa_size' => array( 'small' => '1.00' ) ) ) ),
		);

		self::assertSame( array(), Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon ) );
	}

	public function test_resolver_uses_configured_attribute_when_resolvable(): void {
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->justReturn( 'pa_size' );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_terms' )->justReturn(
			array(
				(object) array( 'slug' => 'small', 'name' => 'Small' ),
				(object) array( 'slug' => 'large', 'name' => 'Large' ),
			)
		);

		$addon = array(
			'variations' => 1,
			'attribute'  => 3,
			'options'    => array(),
		);

		$values = Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon );
		self::assertSame(
			array(
				'pa_size' => array(
					'small' => 'Small',
					'large' => 'Large',
				),
			),
			$values
		);
	}

	/**
	 * Data-detected fallback: attribute=0 (operator never picked one) but options
	 * still have nested prices keyed by a real taxonomy. Resolver should iterate
	 * options and pick up the taxonomy from the FIRST nested-price option — even
	 * if option 0 itself has been flattened to a scalar.
	 */
	public function test_resolver_iterates_past_flattened_option_zero(): void {
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( $tax ) => 'pa_crust' === $tax
		);
		Functions\when( 'get_terms' )->alias(
			static function ( $args ) {
				if ( 'pa_crust' === ( $args['taxonomy'] ?? '' ) ) {
					return array(
						(object) array( 'slug' => 'thin', 'name' => 'Thin' ),
						(object) array( 'slug' => 'thick', 'name' => 'Thick' ),
					);
				}
				return array();
			}
		);

		$addon = array(
			'variations' => 1,
			'attribute'  => 0,
			'options'    => array(
				// Option 0: flattened by a prior bad save — used to short-circuit detection.
				array( 'id' => 'opt-0', 'price' => '1.00' ),
				// Option 1: still nested → resolver should detect from here.
				array(
					'id'    => 'opt-1',
					'price' => array( 'pa_crust' => array( 'thin' => '1.00', 'thick' => '1.50' ) ),
				),
			),
		);

		$values = Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon );
		self::assertSame(
			array(
				'pa_crust' => array(
					'thin'  => 'Thin',
					'thick' => 'Thick',
				),
			),
			$values,
			'Resolver must scan all options for a nested-price match, not stop at option 0.'
		);
	}

	public function test_resolver_handles_addon_missing_options_key(): void {
		$addon = array(
			'variations' => 1,
			'attribute'  => 0,
			// No 'options' key at all.
		);

		self::assertSame( array(), Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon ) );
	}

	public function test_resolver_handles_options_being_non_array(): void {
		$addon = array(
			'variations' => 1,
			'attribute'  => 0,
			'options'    => 'corrupt-string-instead-of-array',
		);

		self::assertSame( array(), Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon ) );
	}

	/* ===== preserve_nested_prices_on_save ===================================== */

	public function test_preserve_short_circuits_when_existing_is_empty(): void {
		$new = array( array( 'name' => 'Toppings', 'variations' => 1, 'options' => array() ) );

		self::assertSame( $new, Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, array() ) );
	}

	/**
	 * `(array) ''` from get_post_meta() on an empty key produces [''], which
	 * would crash a naive iteration that does $candidate['name']. Test that
	 * defensive filter strips it.
	 */
	public function test_preserve_handles_empty_string_meta_safely(): void {
		$new      = array( array( 'name' => 'Toppings', 'variations' => 1, 'options' => array() ) );
		$existing = array( '' );

		self::assertSame( $new, Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing ) );
	}

	public function test_preserve_skips_addon_when_variations_disabled(): void {
		// Operator turned variations off → flat-price save is intentional → don't restore.
		$new = array(
			array(
				'name'       => 'Toppings',
				'variations' => 0,
				'options'    => array(
					array( 'id' => 'opt-1', 'price' => '1.00' ),
				),
			),
		);
		$existing = array(
			array(
				'name'    => 'Toppings',
				'options' => array(
					array( 'id' => 'opt-1', 'price' => array( 'pa_size' => array( 'small' => '1.00' ) ) ),
				),
			),
		);

		$result = Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing );
		self::assertSame( '1.00', $result[0]['options'][0]['price'] );
	}

	public function test_preserve_restores_nested_when_form_flattened_it(): void {
		$nested = array( 'pa_size' => array( 'small' => '1.00', 'large' => '2.00' ) );

		$new = array(
			array(
				'name'       => 'Toppings',
				'variations' => 1,
				'attribute'  => 0,
				'options'    => array(
					array( 'id' => 'opt-1', 'price' => '0.50' ), // flattened by broken form
				),
			),
		);
		$existing = array(
			array(
				'name'    => 'Toppings',
				'options' => array(
					array( 'id' => 'opt-1', 'price' => $nested ),
				),
			),
		);

		$result = Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing );
		self::assertSame( $nested, $result[0]['options'][0]['price'], 'Nested price array must be restored from existing meta.' );
	}

	public function test_preserve_does_not_touch_already_nested_new_prices(): void {
		// Form rendered correctly and submitted nested → preserve should be a no-op.
		$new_nested = array( 'pa_size' => array( 'small' => '5.00' ) );
		$old_nested = array( 'pa_size' => array( 'small' => '1.00' ) );

		$new = array(
			array(
				'name'       => 'Toppings',
				'variations' => 1,
				'options'    => array(
					array( 'id' => 'opt-1', 'price' => $new_nested ),
				),
			),
		);
		$existing = array(
			array(
				'name'    => 'Toppings',
				'options' => array(
					array( 'id' => 'opt-1', 'price' => $old_nested ),
				),
			),
		);

		$result = Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing );
		self::assertSame( $new_nested, $result[0]['options'][0]['price'], 'New nested price must win over existing — preserve only restores when new is scalar.' );
	}

	public function test_preserve_only_matches_options_by_uuid(): void {
		// New option has a different UUID than existing → no match → no preservation.
		$new = array(
			array(
				'name'       => 'Toppings',
				'variations' => 1,
				'options'    => array(
					array( 'id' => 'opt-NEW-uuid', 'price' => '0.50' ),
				),
			),
		);
		$existing = array(
			array(
				'name'    => 'Toppings',
				'options' => array(
					array( 'id' => 'opt-OLD-uuid', 'price' => array( 'pa_size' => array( 'small' => '1.00' ) ) ),
				),
			),
		);

		$result = Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing );
		self::assertSame( '0.50', $result[0]['options'][0]['price'], 'A different UUID is treated as a different option — no preservation.' );
	}

	public function test_preserve_realigns_attribute_id_after_restore(): void {
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( $tax ) => 'pa_size' === $tax
		);
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->alias(
			static fn( $tax ) => 'pa_size' === $tax ? 7 : 0
		);

		$nested = array( 'pa_size' => array( 'small' => '1.00' ) );

		$new = array(
			array(
				'name'       => 'Toppings',
				'variations' => 1,
				'attribute'  => 0, // operator never picked attribute → 0
				'options'    => array(
					array( 'id' => 'opt-1', 'price' => '0.50' ),
				),
			),
		);
		$existing = array(
			array(
				'name'    => 'Toppings',
				'options' => array(
					array( 'id' => 'opt-1', 'price' => $nested ),
				),
			),
		);

		$result = Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing );
		self::assertSame( $nested, $result[0]['options'][0]['price'] );
		self::assertSame( 7, $result[0]['attribute'], 'attribute ID must be re-aligned to the merged taxonomy after restore.' );
	}

	public function test_preserve_handles_malformed_existing_addon_entry(): void {
		// Mixed malformed entries (string, int) interleaved with valid addon array.
		$nested = array( 'pa_size' => array( 'small' => '1.00' ) );

		$new = array(
			array(
				'name'       => 'Toppings',
				'variations' => 1,
				'options'    => array(
					array( 'id' => 'opt-1', 'price' => '0.50' ),
				),
			),
		);
		$existing = array(
			'corrupt-string',
			42,
			array(
				'name'    => 'Toppings',
				'options' => array( array( 'id' => 'opt-1', 'price' => $nested ) ),
			),
		);

		$result = Lafka_Product_Addon_Admin::preserve_nested_prices_on_save( $new, $existing );
		self::assertSame( $nested, $result[0]['options'][0]['price'], 'Malformed entries in existing meta must be skipped, not crash.' );
	}
}
