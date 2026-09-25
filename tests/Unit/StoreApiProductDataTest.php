<?php
/**
 * GX4 Store API product data (incl/store-api/lafka-store-api-product.php):
 * block themes and headless menus read `extensions.lafka.serves` and
 * `extensions.lafka.has_required_addons` from /wc/store/v1/products.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class StoreApiProductDataTest extends TestCase {

	/** @var list<array> */
	private array $registered = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->registered = array();
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		Functions\when( '__' )->returnArg();
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->alias(
			function ( $args ) {
				$this->registered[] = $args;
			}
		);
		Functions\when( 'lafka_get_product_serves' )->alias( static fn( $product ) => 12 === $product->get_id() ? 2 : 0 );
		Functions\when( 'lafka_product_has_required_addons' )->alias( static fn( $id ) => 12 === $id );

		require_once dirname( __DIR__, 2 ) . '/incl/store-api/lafka-store-api-product.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function product( int $id, int $parent = 0 ): object {
		return new class( $id, $parent ) {
			public function __construct( private int $id, private int $parent ) {}
			public function get_id() {
				return $this->id;
			}
			public function get_parent_id() {
				return $this->parent;
			}
		};
	}

	public function test_registers_the_lafka_namespace_on_the_product_endpoint(): void {
		lafka_store_api_product_register();

		self::assertCount( 1, $this->registered );
		self::assertSame( 'product', $this->registered[0]['endpoint'] );
		self::assertSame( 'lafka', $this->registered[0]['namespace'] );
		$schema = call_user_func( $this->registered[0]['schema_callback'] );
		self::assertSame( 'integer', $schema['serves']['type'] );
		self::assertSame( 'boolean', $schema['has_required_addons']['type'] );
	}

	public function test_product_data_carries_serves_and_the_required_choice(): void {
		self::assertSame(
			array(
				'serves'              => 2,
				'has_required_addons' => true,
			),
			lafka_store_api_product_data( self::product( 12 ) )
		);
		self::assertSame(
			array(
				'serves'              => 0,
				'has_required_addons' => false,
			),
			lafka_store_api_product_data( self::product( 13 ) )
		);
	}

	public function test_a_variation_answers_for_its_parent_product(): void {
		self::assertTrue( lafka_store_api_product_data( self::product( 30, 12 ) )['has_required_addons'] );
	}
}
