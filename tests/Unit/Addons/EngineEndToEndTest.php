<?php
/**
 * Phase 4: end-to-end integration test that exercises the engine pipeline
 * for each pricing mode and asserts the stored shape is what the legacy
 * cart code (Lafka_Product_Addon_Cart::apply_attribute_specific_price)
 * expects to read.
 *
 * Why this exists: until Phase 5 refactors the cart layer, the legacy
 * cart class reads `_product_addons` meta directly. The engine must
 * write a shape compatible with that reader. This test pins that
 * contract — if a future engine change accidentally writes a different
 * shape, this test fails before the change ships.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Schema;
use Lafka_Addons_Engine;
use Lafka_Engine_Editor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class EngineEndToEndTest extends TestCase {

	private array $stored_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stored_meta = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->alias( static fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->alias(
			static fn( $id ) => 1 === (int) $id ? 'pa_size' : ''
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) {
				return '_product_addons' === $key ? ( $this->stored_meta[ $post_id ] ?? array() ) : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				if ( '_product_addons' === $key ) {
					$this->stored_meta[ $post_id ] = $value;
				}
				return true;
			}
		);

		// Reset engine singleton.
		$ref      = new ReflectionClass( Lafka_Addons_Engine::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function save_via_editor( int $post_id, array $post_data ): void {
		$editor = new Lafka_Engine_Editor();
		$groups = $editor->parse_groups( $post_data );
		$groups = $editor->expand_groups( $groups );
		Lafka_Addons_Engine::instance()->repository()->save_groups( $post_id, $groups );
	}

	/**
	 * Flat-group mode: every option's stored price = the single group price.
	 * Cart code reads scalar prices directly — no per-attribute resolution.
	 */
	public function test_flat_group_writes_scalar_price_to_every_option(): void {
		$this->save_via_editor( 1, array(
			'lafka_addon_groups' => array(
				array(
					'name'             => 'Premium Toppings',
					'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
					'options_source'   => Lafka_Addon_Schema::SOURCE_MANUAL,
					'group_flat_price' => '1.50',
					'options'          => array(
						array( 'id' => 'a', 'label' => 'Cheese' ),
						array( 'id' => 'b', 'label' => 'Truffle' ),
					),
				),
			),
		) );

		$stored = $this->stored_meta[1];
		self::assertSame( '1.50', $stored[0]['options'][0]['price'] );
		self::assertSame( '1.50', $stored[0]['options'][1]['price'] );
	}

	/**
	 * Flat-per-option: each option keeps its own scalar price (passthrough).
	 */
	public function test_flat_per_option_keeps_per_option_scalars(): void {
		$this->save_via_editor( 2, array(
			'lafka_addon_groups' => array(
				array(
					'name'           => 'Toppings',
					'pricing_mode'   => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
					'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
					'options'        => array(
						array( 'id' => 'a', 'label' => 'Cheese',  'price' => '1.00' ),
						array( 'id' => 'b', 'label' => 'Truffle', 'price' => '3.00' ),
					),
				),
			),
		) );

		$stored = $this->stored_meta[2];
		self::assertSame( '1.00', $stored[0]['options'][0]['price'] );
		self::assertSame( '3.00', $stored[0]['options'][1]['price'] );
	}

	/**
	 * Flat-per-size: every option gets the same nested matrix. Cart code
	 * reads the matrix and applies per-attribute pricing.
	 *
	 * Storage shape: options[i]['price'] = ['pa_size' => ['small' => $X, ...]]
	 *
	 * This is the shape Lafka_Product_Addon_Cart::apply_attribute_specific_price
	 * expects to find when iterating $cart_item['variation'].
	 */
	public function test_flat_per_size_writes_uniform_matrix_to_every_option(): void {
		$this->save_via_editor( 3, array(
			'lafka_addon_groups' => array(
				array(
					'name'              => 'Toppings',
					'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
					'options_source'    => Lafka_Addon_Schema::SOURCE_MANUAL,
					'variations'        => '1',
					'attribute'         => '1',
					'group_size_prices' => array(
						'small'  => '0.50',
						'medium' => '1.00',
						'large'  => '1.50',
					),
					'options'           => array(
						array( 'id' => 'a', 'label' => 'Cheese' ),
						array( 'id' => 'b', 'label' => 'Mushroom' ),
					),
				),
			),
		) );

		$stored          = $this->stored_meta[3];
		$expected_matrix = array(
			'pa_size' => array(
				'small'  => '0.50',
				'medium' => '1.00',
				'large'  => '1.50',
			),
		);
		self::assertSame( $expected_matrix, $stored[0]['options'][0]['price'] );
		self::assertSame( $expected_matrix, $stored[0]['options'][1]['price'] );
	}

	/**
	 * Matrix mode: each option keeps its OWN per-attribute matrix.
	 */
	public function test_matrix_mode_preserves_per_option_matrices(): void {
		$this->save_via_editor( 4, array(
			'lafka_addon_groups' => array(
				array(
					'name'           => 'Toppings',
					'pricing_mode'   => Lafka_Addon_Schema::PRICING_MATRIX,
					'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
					'variations'     => '1',
					'attribute'      => '1',
					'options'        => array(
						array(
							'id'    => 'a',
							'label' => 'Cheese',
							'matrix_price' => array(
								'pa_size' => array( 'small' => '0.50', 'medium' => '1.00' ),
							),
						),
						array(
							'id'    => 'b',
							'label' => 'Truffle',
							'matrix_price' => array(
								'pa_size' => array( 'small' => '2.00', 'medium' => '3.00' ),
							),
						),
					),
				),
			),
		) );

		$stored = $this->stored_meta[4];
		self::assertSame( '1.00', $stored[0]['options'][0]['price']['pa_size']['medium'] );
		self::assertSame( '3.00', $stored[0]['options'][1]['price']['pa_size']['medium'] );
	}

	/**
	 * Round-trip via the repository: save_groups → get_groups returns
	 * value objects with all v2 fields intact.
	 */
	public function test_round_trip_via_repository(): void {
		$this->save_via_editor( 5, array(
			'lafka_addon_groups' => array(
				array(
					'name'                     => 'Crust',
					'pricing_mode'             => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
					'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
					'options_source_attribute' => 'pa_premium_toppings',
					'variations'               => '1',
					'attribute'                => '1',
					'included_size_slugs'      => array( 'medium', 'large' ),
					'group_size_prices'        => array( 'medium' => '1.00', 'large' => '1.50' ),
					'options'                  => array( array( 'id' => 'a', 'label' => 'Thin' ) ),
				),
			),
		) );

		$loaded = Lafka_Addons_Engine::instance()->repository()->get_groups( 5 );

		self::assertCount( 1, $loaded );
		self::assertSame( 'Crust', $loaded[0]->name );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $loaded[0]->pricing_mode );
		self::assertSame( Lafka_Addon_Schema::SOURCE_ATTRIBUTE, $loaded[0]->options_source );
		self::assertSame( 'pa_premium_toppings', $loaded[0]->options_source_attribute );
		self::assertSame( array( 'medium', 'large' ), $loaded[0]->included_size_slugs );
		self::assertSame( array( 'medium' => '1.00', 'large' => '1.50' ), $loaded[0]->group_size_prices );
	}

	/**
	 * Cart-side contract check: the legacy
	 * Lafka_Product_Addon_Cart::apply_attribute_specific_price() walks
	 * $cart_item['variation'] and looks up $addon['price'][taxonomy][slug].
	 *
	 * After flat_per_size expand, the lookup must resolve to a scalar.
	 */
	public function test_flat_per_size_output_matches_legacy_cart_lookup_contract(): void {
		$this->save_via_editor( 6, array(
			'lafka_addon_groups' => array(
				array(
					'name'              => 'Toppings',
					'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
					'options_source'    => Lafka_Addon_Schema::SOURCE_MANUAL,
					'variations'        => '1',
					'attribute'         => '1',
					'group_size_prices' => array( 'medium' => '1.00' ),
					'options'           => array( array( 'id' => 'a', 'label' => 'Cheese' ) ),
				),
			),
		) );

		$stored      = $this->stored_meta[6];
		$option_price = $stored[0]['options'][0]['price'];
		$variation   = array( 'attribute_pa_size' => 'medium' );

		// Reproduce the legacy cart's lookup logic.
		$resolved = null;
		foreach ( $variation as $prefixed_name => $value ) {
			$bare = str_replace( 'attribute_', '', $prefixed_name );
			if ( isset( $option_price[ $bare ][ $value ] ) ) {
				$resolved = $option_price[ $bare ][ $value ];
				break;
			}
		}

		self::assertSame( '1.00', $resolved, 'Legacy cart lookup must resolve to the operator-entered scalar.' );
	}
}
