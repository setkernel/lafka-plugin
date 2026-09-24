<?php
/**
 * Editor save pipeline end to end: POST → Lafka_Engine_Editor::parse_groups()
 * → expand_groups() (pricing strategy) → repository → `_product_addons` meta,
 * and the stored shape priced by the cart (Lafka_Engine_Cart).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Schema;
use Lafka_Addons_Engine;
use Lafka_Engine_Cart;
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

		( new ReflectionClass( Lafka_Addons_Engine::class ) )->getProperty( 'instance' )->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Save one POSTed group through the editor pipeline and return the stored
	 * `_product_addons[0]` entry.
	 */
	private function save_group( array $raw_group ): array {
		$editor = new Lafka_Engine_Editor();
		$groups = $editor->expand_groups( $editor->parse_groups( array( 'lafka_addon_groups' => array( $raw_group ) ) ) );
		Lafka_Addons_Engine::instance()->repository()->save_groups( 1, $groups );

		return $this->stored_meta[1][0];
	}

	public function test_flat_group_writes_scalar_price_to_every_option(): void {
		$stored = $this->save_group(
			array(
				'name'             => 'Premium Toppings',
				'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
				'group_flat_price' => '1.50',
				'options'          => array(
					array( 'id' => 'a', 'label' => 'Cheese' ),
					array( 'id' => 'b', 'label' => 'Truffle' ),
				),
			)
		);

		self::assertSame( array( '1.50', '1.50' ), array_column( $stored['options'], 'price' ) );
	}

	public function test_flat_per_option_keeps_per_option_scalars(): void {
		$stored = $this->save_group(
			array(
				'name'         => 'Toppings',
				'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
				'options'      => array(
					array( 'id' => 'a', 'label' => 'Cheese', 'price' => '1.00' ),
					array( 'id' => 'b', 'label' => 'Truffle', 'price' => '3.00' ),
				),
			)
		);

		self::assertSame( array( '1.00', '3.00' ), array_column( $stored['options'], 'price' ) );
	}

	public function test_flat_per_size_writes_uniform_matrix_to_every_option(): void {
		$stored = $this->save_group(
			array(
				'name'              => 'Toppings',
				'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
				'variations'        => '1',
				'attribute'         => '1',
				'group_size_prices' => array( 'small' => '0.50', 'medium' => '1.00', 'large' => '1.50' ),
				'options'           => array(
					array( 'id' => 'a', 'label' => 'Cheese' ),
					array( 'id' => 'b', 'label' => 'Mushroom' ),
				),
			)
		);

		$expected = array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00', 'large' => '1.50' ) );
		self::assertSame( array( $expected, $expected ), array_column( $stored['options'], 'price' ) );
	}

	public function test_matrix_mode_preserves_per_option_matrices(): void {
		$stored = $this->save_group(
			array(
				'name'         => 'Toppings',
				'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
				'variations'   => '1',
				'attribute'    => '1',
				'options'      => array(
					array(
						'id'           => 'a',
						'label'        => 'Cheese',
						'matrix_price' => array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00' ) ),
					),
					array(
						'id'           => 'b',
						'label'        => 'Truffle',
						'matrix_price' => array( 'pa_size' => array( 'small' => '2.00', 'medium' => '3.00' ) ),
					),
				),
			)
		);

		self::assertSame( '1.00', $stored['options'][0]['price']['pa_size']['medium'] );
		self::assertSame( '3.00', $stored['options'][1]['price']['pa_size']['medium'] );
	}

	/**
	 * The stored flat_per_size shape is what the cart prices: the customer's
	 * chosen size resolves to the operator-entered amount for that size.
	 */
	public function test_cart_prices_the_stored_per_size_shape_by_chosen_size(): void {
		$stored = $this->save_group(
			array(
				'name'              => 'Toppings',
				'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
				'variations'        => '1',
				'attribute'         => '1',
				'group_size_prices' => array( 'small' => '0.50', 'medium' => '1.00', 'large' => '1.50' ),
				'options'           => array( array( 'id' => 'a', 'label' => 'Cheese' ) ),
			)
		);

		$priced = ( new Lafka_Engine_Cart() )->apply_attribute_specific_price(
			array(
				array(
					'name'  => 'Toppings',
					'price' => $stored['options'][0]['price'],
				),
			),
			array( 'variation' => array( 'attribute_pa_size' => 'large' ) )
		);

		self::assertSame( '1.50', $priced[0]['price'] );
	}

	public function test_unknown_pricing_mode_is_saved_as_flat_per_option(): void {
		// A tampered / stale form must not persist a mode no strategy understands.
		$stored = $this->save_group(
			array(
				'name'         => 'G',
				'pricing_mode' => 'something_unknown',
				'options'      => array( array( 'id' => 'x', 'label' => 'X', 'price' => '1.00' ) ),
			)
		);

		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $stored['pricing_mode'] );
		self::assertSame( '1.00', $stored['options'][0]['price'] );
	}

	public function test_included_size_slugs_are_normalized(): void {
		$stored = $this->save_group(
			array(
				'name'                => 'G',
				'pricing_mode'        => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
				'included_size_slugs' => array( 'Small', 'Medium', 'Large' ),
				'group_size_prices'   => array( 'small' => '0.50' ),
				'options'             => array( array( 'id' => 'x', 'label' => 'X' ) ),
			)
		);

		self::assertSame( array( 'small', 'medium', 'large' ), $stored['included_size_slugs'] );
	}
}
