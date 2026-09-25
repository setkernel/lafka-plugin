<?php
/**
 * GX4 "serves N" product field (incl/woocommerce/lafka-product-serves.php):
 *
 *   - the value is a whole number of people, 0-50; 0 means "not set";
 *   - the product editor saves it through WooCommerce's own product save
 *     (whose nonce + capability checks already ran), and clears it at 0;
 *   - `lafka_product_serves` resolves it (a variation reads its parent), and
 *     never invents a value;
 *   - REST: the value reads on `lafka_serves` and writes only for a user
 *     who may edit the product.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';
require_once __DIR__ . '/Stubs/wp-error-class.php';

final class ProductServesTest extends TestCase {

	/** @var array<int, array<string, mixed>> product id => meta */
	public array $meta = array();

	/** @var array<int, object> */
	private array $products = array();

	private bool $can_edit = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST          = array();
		$this->meta     = array();
		$this->can_edit = true;

		$this->products = array(
			10 => $this->product( 10, 0 ),
			11 => $this->product( 11, 10 ),
		);

		Functions\when( 'apply_filters' )->alias( array( Hooks::class, 'apply' ) );
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $this->products[ (int) $id ] ?? false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->alias( fn( $cap, $id = 0 ) => $this->can_edit && 'edit_post' === $cap );
		Functions\when( '__' )->returnArg();

		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-product-serves.php';
		Hooks::reset();
		lafka_product_serves_init();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function product( int $id, int $parent ): object {
		return new class( $this, $id, $parent ) {
			public function __construct( private ProductServesTest $test, private int $id, private int $parent ) {}
			public function get_id() {
				return $this->id;
			}
			public function get_parent_id() {
				return $this->parent;
			}
			public function get_meta( $key, $single = true ) {
				return $this->test->meta[ $this->id ][ $key ] ?? '';
			}
			public function update_meta_data( $key, $value ) {
				$this->test->meta[ $this->id ][ $key ] = $value;
			}
			public function delete_meta_data( $key ) {
				unset( $this->test->meta[ $this->id ][ $key ] );
			}
		};
	}

	public function test_sanitises_to_whole_people_between_zero_and_fifty(): void {
		self::assertSame( 3, lafka_sanitize_product_serves( '3' ) );
		self::assertSame( 2, lafka_sanitize_product_serves( '2.7' ) );
		self::assertSame( 0, lafka_sanitize_product_serves( '-4' ) );
		self::assertSame( 0, lafka_sanitize_product_serves( 'two' ) );
		self::assertSame( 0, lafka_sanitize_product_serves( '' ) );
		self::assertSame( 50, lafka_sanitize_product_serves( '400' ) );
	}

	public function test_saving_the_editor_field_stores_the_value_and_zero_clears_it(): void {
		$product = $this->products[10];

		$_POST['_lafka_serves'] = '2';
		lafka_product_serves_save( $product );
		self::assertSame( 2, $this->meta[10]['_lafka_serves'] );

		$_POST['_lafka_serves'] = '0';
		lafka_product_serves_save( $product );
		self::assertArrayNotHasKey( '_lafka_serves', $this->meta[10] ?? array() );
	}

	public function test_a_save_without_the_field_leaves_the_value_alone(): void {
		$this->meta[10]['_lafka_serves'] = 3;

		lafka_product_serves_save( $this->products[10] );

		self::assertSame( 3, $this->meta[10]['_lafka_serves'] );
	}

	public function test_unset_means_zero_never_an_invented_value(): void {
		self::assertSame( 0, lafka_get_product_serves( $this->products[10] ) );
		self::assertSame( 0, lafka_get_product_serves( 999 ) );
	}

	public function test_the_filter_reads_the_saved_value_and_a_variation_reads_its_parent(): void {
		$this->meta[10]['_lafka_serves'] = '3';

		self::assertSame( 3, lafka_get_product_serves( $this->products[10] ) );
		self::assertSame( 3, lafka_get_product_serves( 10 ) );
		self::assertSame( 3, lafka_get_product_serves( $this->products[11] ), 'A variation inherits the parent value.' );
	}

	public function test_an_earlier_filter_value_wins_over_the_meta(): void {
		$this->meta[10]['_lafka_serves'] = '3';
		add_filter( 'lafka_product_serves', static fn( $n ) => 4, 5 );

		self::assertSame( 4, lafka_get_product_serves( $this->products[10] ) );
	}

	public function test_rest_reads_the_value_and_writes_only_for_an_editor(): void {
		$this->meta[10]['_lafka_serves'] = 2;

		self::assertSame( 2, lafka_product_serves_rest_get( array( 'id' => 10 ) ) );

		self::assertTrue( lafka_product_serves_rest_update( 5, $this->products[10] ) );
		self::assertSame( 5, $this->meta[10]['_lafka_serves'] );

		$this->can_edit = false;
		$result         = lafka_product_serves_rest_update( 7, $this->products[10] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 5, $this->meta[10]['_lafka_serves'] );
	}

	public function test_admin_field_and_save_are_wired_to_the_product_editor(): void {
		$registered = Hooks::registered();

		self::assertContains( 'woocommerce_product_options_general_product_data -> lafka_product_serves_field', $registered );
		self::assertContains( 'woocommerce_admin_process_product_object -> lafka_product_serves_save', $registered );
		self::assertContains( 'rest_api_init -> lafka_product_serves_register_rest', $registered );
	}
}
