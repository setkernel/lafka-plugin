<?php
/**
 * GDPR export/erase finds the add-on selections the cart actually stores on
 * order items (display-name keys), for new orders (via the key index the
 * cart records) and older ones (via the product's add-on group names).
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Engine_Cart;
use Lafka_Engine_Helper;
use Lafka_Engine_Privacy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

/**
 * Minimal order-item double with WC's meta API.
 */
final class FakeOrderItem {
	/** @var array<string, mixed> */
	public array $meta;
	public bool $saved = false;

	public function __construct( array $meta, private int $product_id = 7 ) {
		$this->meta = $meta;
	}
	public function get_meta_data(): array {
		$out = array();
		foreach ( $this->meta as $key => $value ) {
			$out[] = (object) array(
				'key'   => $key,
				'value' => $value,
			);
		}
		return $out;
	}
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}
	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function get_product_id(): int {
		return $this->product_id;
	}
	public function save() {
		$this->saved = true;
	}
}

final class AddonPrivacyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Lafka_Engine_Helper::clear_cache();
	}

	protected function tearDown(): void {
		Lafka_Engine_Helper::clear_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function orders_with( FakeOrderItem $item ): void {
		$order = new class( $item ) {
			public function __construct( private $item ) {}
			public function get_items() {
				return array( 11 => $this->item );
			}
		};
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );
	}

	public function test_cart_records_which_item_meta_are_addon_selections(): void {
		$item = new FakeOrderItem( array() );
		$cart = new Lafka_Engine_Cart();

		$cart->order_line_item(
			$item,
			'key',
			array(
				'addons' => array(
					array(
						'name'  => 'Extra Toppings',
						'value' => 'Extra Cheese',
						'price' => 0,
					),
					array(
						'name'  => 'Note',
						'value' => 'Ring twice',
						'price' => 0,
					),
				),
			)
		);

		$this->assertSame( 'Extra Cheese', $item->meta['Extra Toppings'] );
		$this->assertSame( array( 'Extra Toppings', 'Note' ), $item->meta[ Lafka_Engine_Privacy::KEYS_META ] );
	}

	public function test_export_and_erase_use_the_recorded_keys(): void {
		$item = new FakeOrderItem(
			array(
				'Extra Toppings ($1.50)'         => 'Extra Cheese',
				'Note'                           => 'Ring twice',
				'_reduced_stock'                 => '1',
				Lafka_Engine_Privacy::KEYS_META => array( 'Extra Toppings ($1.50)', 'Note' ),
			)
		);
		$this->orders_with( $item );
		$privacy = new Lafka_Engine_Privacy();

		$export = $privacy->export( 'buyer@example.test' );
		$this->assertSame(
			array(
				array(
					'name'  => 'Extra Toppings ($1.50)',
					'value' => 'Extra Cheese',
				),
				array(
					'name'  => 'Note',
					'value' => 'Ring twice',
				),
			),
			$export['data'][0]['data']
		);

		$erase = $privacy->erase( 'buyer@example.test' );
		$this->assertSame( 2, $erase['items_removed'] );
		$this->assertSame( array( '_reduced_stock' => '1' ), $item->meta, 'Only add-on data (and its index) is erased.' );
		$this->assertTrue( $item->saved );
	}

	public function test_orders_without_the_index_match_the_products_addon_names(): void {
		// Seed the helper's per-request cache with the product's current groups.
		$cache = ( new ReflectionClass( Lafka_Engine_Helper::class ) )->getProperty( 'product_addons_cache' );
		$cache->setValue( null, array( '7|default|1|1|' => array( array( 'name' => 'Extra Toppings' ) ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$item = new FakeOrderItem(
			array(
				'Extra Toppings ($1.50)' => 'Extra Cheese',
				'_reduced_stock'         => '1',
			)
		);
		$this->orders_with( $item );

		( new Lafka_Engine_Privacy() )->erase( 'buyer@example.test' );

		$this->assertSame( array( '_reduced_stock' => '1' ), $item->meta );
	}
}
