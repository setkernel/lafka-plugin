<?php
/**
 * Variation options in a sensible order (GX0). The live store showed pizza
 * sizes as "Medium / Large / Small / X-Large": get_variation_attributes()
 * returns a taxonomy attribute's values in database row order, and templates
 * that iterate it (the PDP size chips) ignored the operator's term order.
 *
 * Rule: an explicit order wins (custom term order, or an attribute sorted by
 * name/id); without one, options go by their lowest variation price,
 * cheapest first; otherwise WooCommerce's order stands.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';

final class VariationOrderTest extends TestCase {

	/** @var array<int, array{attrs: array<string, string>, price: float}> */
	private array $variations = array();

	/** @var array<string, int> slug => term order meta ('' when unset). */
	private array $term_order = array();

	private string $orderby = 'menu_order';

	/** @var array<string, mixed> */
	private array $theme_mods = array();

	/** @var array<string, callable> */
	private array $filters = array();

	private object $product;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$test          = $this;
		$this->product = new class( $test ) {
			public function __construct( private VariationOrderTest $test ) {}
			public function get_id() {
				return 10;
			}
			public function is_type( $type ) {
				return 'variable' === $type;
			}
			public function get_children() {
				return array_keys( $this->test->variations() );
			}
			public function get_variation_prices( $for_display = false ) {
				return array( 'price' => array_map( static fn( $v ) => $v['price'], $this->test->variations() ) );
			}
		};
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => 10 === (int) $id ? $this->product : null );
		Functions\when( 'wc_get_product_variation_attributes' )->alias( fn( $id ) => $this->variations[ $id ]['attrs'] ?? array() );
		Functions\when( 'sanitize_title' )->alias( static fn( $v ) => strtolower( str_replace( ' ', '-', (string) $v ) ) );
		Functions\when( 'taxonomy_exists' )->alias( static fn( $t ) => 0 === strpos( (string) $t, 'pa_' ) );
		Functions\when( 'wc_attribute_orderby' )->alias( fn() => $this->orderby );
		Functions\when( 'get_terms' )->alias(
			fn( $args ) => array_map(
				static fn( $slug ) => (object) array(
					'term_id' => crc32( $slug ),
					'slug'    => $slug,
				),
				$args['slug']
			)
		);
		Functions\when( 'get_term_meta' )->alias(
			function ( $term_id, $key ) {
				foreach ( $this->term_order as $slug => $order ) {
					if ( crc32( $slug ) === $term_id && 'order' === $key ) {
						return (string) $order;
					}
				}
				return '';
			}
		);
		// WooCommerce orders a product's attribute terms by the attribute's
		// "Default sort order" (custom term order, or name).
		Functions\when( 'wc_get_product_terms' )->alias(
			function ( $product_id, $taxonomy, $args ) {
				$slugs = array();
				foreach ( $this->variations as $variation ) {
					$slugs[] = $variation['attrs'][ 'attribute_' . $taxonomy ] ?? '';
				}
				$slugs = array_values( array_unique( array_filter( $slugs ) ) );
				if ( 'name' === $this->orderby ) {
					sort( $slugs );
				} else {
					usort( $slugs, fn( $a, $b ) => (int) ( $this->term_order[ $a ] ?? 0 ) <=> (int) ( $this->term_order[ $b ] ?? 0 ) );
				}
				return $slugs;
			}
		);
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $fallback = false ) => $this->theme_mods[ $key ] ?? $fallback );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				return isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value;
			}
		);
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-variation-order.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<int, array{attrs: array<string, string>, price: float}> */
	public function variations(): array {
		return $this->variations;
	}

	/** Four pizza sizes created out of order, priced by size. */
	private function pizza_sizes(): void {
		$this->variations = array(
			105 => array(
				'attrs' => array( 'attribute_pa_size' => 'medium' ),
				'price' => 12.5,
			),
			106 => array(
				'attrs' => array( 'attribute_pa_size' => 'large' ),
				'price' => 17.5,
			),
			168 => array(
				'attrs' => array( 'attribute_pa_size' => 'small' ),
				'price' => 8.5,
			),
			171 => array(
				'attrs' => array( 'attribute_pa_size' => 'x-large' ),
				'price' => 21.5,
			),
		);
	}

	private const DB_ORDER = array( 'medium', 'large', 'small', 'x-large' );

	public function test_an_explicit_term_order_wins(): void {
		$this->pizza_sizes();
		$this->term_order = array(
			'x-large' => 1,
			'small'   => 2,
			'medium'  => 3,
			'large'   => 4,
		);

		$this->assertSame( array( 'x-large', 'small', 'medium', 'large' ), lafka_sort_variation_options( $this->product, 'pa_size', self::DB_ORDER ) );
	}

	public function test_without_an_explicit_order_sizes_go_cheapest_first(): void {
		$this->pizza_sizes();
		$this->term_order = array(
			'medium'  => 0,
			'large'   => 0,
			'small'   => 0,
			'x-large' => 0,
		);

		$this->assertSame( array( 'small', 'medium', 'large', 'x-large' ), lafka_sort_variation_options( $this->product, 'pa_size', self::DB_ORDER ) );
	}

	public function test_custom_text_attributes_go_cheapest_first(): void {
		$this->variations = array(
			1 => array(
				'attrs' => array( 'attribute_portion' => 'Family' ),
				'price' => 30.0,
			),
			2 => array(
				'attrs' => array( 'attribute_portion' => 'Single' ),
				'price' => 10.0,
			),
		);

		$this->assertSame( array( 'Single', 'Family' ), lafka_sort_variation_options( $this->product, 'Portion', array( 'Family', 'Single' ) ) );
	}

	public function test_an_attribute_sorted_by_name_follows_that_rule(): void {
		$this->pizza_sizes();
		$this->orderby = 'name';

		$this->assertSame( array( 'large', 'medium', 'small', 'x-large' ), lafka_sort_variation_options( $this->product, 'pa_size', self::DB_ORDER ) );
	}

	public function test_equal_prices_keep_the_woocommerce_order(): void {
		$this->variations = array(
			1 => array(
				'attrs' => array( 'attribute_pa_crust' => 'thin' ),
				'price' => 10.0,
			),
			2 => array(
				'attrs' => array( 'attribute_pa_crust' => 'regular' ),
				'price' => 10.0,
			),
		);

		$this->assertSame( array( 'thin', 'regular' ), lafka_sort_variation_options( $this->product, 'pa_crust', array( 'thin', 'regular' ) ) );
	}

	public function test_price_sorting_can_be_switched_off_and_filtered(): void {
		$this->pizza_sizes();

		$this->theme_mods['lafka_sort_variation_options'] = 'no';
		$this->assertSame( self::DB_ORDER, lafka_sort_variation_options( $this->product, 'pa_size', self::DB_ORDER ) );

		unset( $this->theme_mods['lafka_sort_variation_options'] );
		$this->filters['lafka_sort_variation_options_by_price'] = static fn( $on, $attribute ) => 'pa_size' !== $attribute;
		$this->assertSame( self::DB_ORDER, lafka_sort_variation_options( $this->product, 'pa_size', self::DB_ORDER ) );
	}

	public function test_woocommerce_dropdowns_and_swatches_follow_the_price_order(): void {
		$this->pizza_sizes();
		$terms = array_map( static fn( $slug ) => (object) array( 'slug' => $slug ), self::DB_ORDER );

		$sorted = lafka_filter_product_terms_order( $terms, 10, 'pa_size', array( 'fields' => 'all' ) );

		$this->assertSame( array( 'small', 'medium', 'large', 'x-large' ), array_map( static fn( $t ) => $t->slug, $sorted ) );
		$this->assertSame( $terms, lafka_filter_product_terms_order( $terms, 10, 'product_cat', array() ), 'Not an attribute.' );
		$this->assertSame( array( 'a', 'b' ), lafka_filter_product_terms_order( array( 'a', 'b' ), 10, 'pa_size', array( 'fields' => 'ids' ) ), 'Ids cannot be matched to options.' );
	}

	public function test_custom_attribute_dropdown_options_are_sorted(): void {
		$this->variations = array(
			1 => array(
				'attrs' => array( 'attribute_portion' => 'Family' ),
				'price' => 30.0,
			),
			2 => array(
				'attrs' => array( 'attribute_portion' => 'Single' ),
				'price' => 10.0,
			),
		);

		$args = lafka_sort_dropdown_variation_options(
			array(
				'options'   => array( 'Family', 'Single' ),
				'attribute' => 'Portion',
				'product'   => $this->product,
			)
		);

		$this->assertSame( array( 'Single', 'Family' ), $args['options'] );
	}

	public function test_menu_rows_go_cheapest_first_unless_the_operator_ordered_the_variations(): void {
		$rows = array(
			array(
				'variation_id'  => 105,
				'display_price' => 12.5,
			),
			array(
				'variation_id'  => 168,
				'display_price' => 8.5,
			),
		);
		$menu_order = array(
			105 => 0,
			168 => 0,
		);
		Functions\when( 'get_post_field' )->alias( static fn( $field, $id ) => $menu_order[ $id ] ?? 0 );

		$this->assertSame( array( 168, 105 ), array_column( lafka_sort_variation_rows( $this->product, $rows ), 'variation_id' ) );

		Functions\when( 'get_post_field' )->alias( static fn( $field, $id ) => 105 === $id ? 1 : 2 );
		$this->assertSame( array( 105, 168 ), array_column( lafka_sort_variation_rows( $this->product, $rows ), 'variation_id' ), 'Explicit variation order stands.' );
	}

	public function test_hooks(): void {
		Hooks::reset();

		lafka_variation_order_init();

		$this->assertSame(
			array(
				'woocommerce_get_product_terms -> lafka_filter_product_terms_order',
				'woocommerce_dropdown_variation_attribute_options_args -> lafka_sort_dropdown_variation_options',
			),
			Hooks::registered()
		);
	}
}
