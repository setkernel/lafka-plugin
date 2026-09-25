<?php
/**
 * Cart-drawer "Complete your meal" upsell: up to three one-tap-addable
 * suggestions (simple, visible, purchasable, in stock) that are not already
 * in the cart, refreshed through the add-to-cart fragments.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CartDrawerUpsellTest extends TestCase {

	/** @var array<int, int[]> Product id => category ids. */
	public static array $cats = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::$cats = array();
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'get_term_by' )->justReturn( false );
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-upsell-row.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-upsell.php';

		// Best sellers (object cache): 5 is in the cart, 9 is variable, 40 is
		// out of stock, 12 and 41 are addable.
		Functions\when( 'wp_cache_get' )->justReturn( array( 5, 9, 12, 40, 41 ) );
		Functions\when( 'wc_get_products' )->alias(
			// Popular simple products fill the remaining slots; the best-seller
			// top-up query (no 'type') adds nothing here.
			static fn( $args ) => 'simple' === ( $args['type'] ?? '' ) ? array( 12, 77, 78 ) : array()
		);
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => new class( (int) $id ) {
				public function __construct( private int $id ) {}
				public function is_visible() {
					return true;
				}
				public function is_purchasable() {
					return true;
				}
				public function is_in_stock() {
					return 40 !== $this->id;
				}
				public function is_type( $type ) {
					return 'variable' === $type && 9 === $this->id;
				}
				public function get_name() {
					return 'Side ' . $this->id;
				}
				public function get_category_ids() {
					return CartDrawerUpsellTest::$cats[ $this->id ] ?? array();
				}
				public function get_price_html() {
					return '$3.00';
				}
				public function add_to_cart_url() {
					return '?add-to-cart=' . $this->id;
				}
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cart_with( array $product_ids ): void {
		$cart = new class( $product_ids ) {
			public function __construct( private array $ids ) {}
			public function get_cart() {
				return array_map(
					static fn( $id ) => array(
						'product_id' => $id,
						'data'       => \wc_get_product( $id ),
					),
					$this->ids
				);
			}
			public function is_empty() {
				return empty( $this->ids );
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	public function test_suggests_three_one_tap_products_not_already_in_the_cart(): void {
		$this->cart_with( array( 5 ) );

		$ids = lafka_cart_drawer_get_upsell_ids();

		self::assertCount( 3, $ids );
		self::assertSame( array(), array_diff( $ids, array( 12, 41, 77, 78 ) ), 'Only addable simple products: not the cart item (5), the variable (9) or the out-of-stock one (40).' );
		self::assertSame( $ids, lafka_cart_drawer_get_upsell_ids(), 'Stable for one cart: a refresh never reshuffles the row.' );
	}

	public function test_deals_and_combos_are_never_the_little_extra(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $fallback = false ) => 'lafka_counter_deals_cat' === $key ? 300 : $fallback );
		Functions\when( 'get_term_by' )->alias( static fn( $field, $slug ) => 'combos' === $slug ? (object) array( 'term_id' => 301 ) : false );
		self::$cats = array(
			12 => array( 300 ),
			41 => array( 301 ),
		);
		$this->cart_with( array( 5 ) );

		self::assertSame( array( 77, 78 ), array_values( array_intersect( array( 77, 78 ), lafka_cart_drawer_get_upsell_ids() ) ) );
		self::assertSame( array(), array_intersect( array( 12, 41 ), lafka_cart_drawer_get_upsell_ids() ), 'Deals category (theme setting) and a category named combos.' );
	}

	public function test_something_new_to_the_order_comes_first(): void {
		// The cart holds a pizza (category 7); 12 and 41 are pizzas too, 77 is a drink.
		self::$cats = array(
			5  => array( 7 ),
			12 => array( 7 ),
			41 => array( 7 ),
			77 => array( 8 ),
			78 => array( 7 ),
		);
		$this->cart_with( array( 5 ) );

		self::assertSame( 77, lafka_cart_drawer_get_upsell_ids()[0] );
	}

	public function test_the_row_rotates_as_the_order_changes(): void {
		Functions\when( 'wc_get_products' )->alias(
			static fn( $args ) => 'simple' === ( $args['type'] ?? '' ) ? array( 12, 77, 78, 79, 80, 81 ) : array()
		);
		$seen = array();
		foreach ( array( array( 5 ), array( 5, 6 ), array( 6 ), array( 7 ), array( 5, 7 ), array( 8 ) ) as $cart ) {
			$this->cart_with( $cart );
			$seen[ implode( ',', lafka_cart_drawer_get_upsell_ids() ) ] = true;
		}

		self::assertGreaterThan( 1, count( $seen ), 'Not always the same three.' );
	}

	public function test_fragment_renders_native_ajax_add_buttons_and_empties_with_the_cart(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/p/' . $id );
		Functions\when( 'get_the_post_thumbnail' )->justReturn( '' );

		$this->cart_with( array( 5 ) );
		$html = lafka_cart_drawer_upsell_fragment( array() )['div.lafka-cart-drawer__upsell'];

		preg_match_all( '/class="lafka-cart-drawer__upsell-add add_to_cart_button ajax_add_to_cart"/', $html, $buttons );
		self::assertCount( 3, $buttons[0], "WooCommerce's ajax add-to-cart handles the one-tap add." );
		$ids = lafka_cart_drawer_get_upsell_ids();
		self::assertStringContainsString( 'data-product_id="' . $ids[0] . '"', $html );
		self::assertStringContainsString( 'href="?add-to-cart=' . $ids[1] . '"', $html );

		$this->cart_with( array() );
		self::assertSame(
			'<div class="lafka-cart-drawer__upsell" data-lafka-drawer-upsell></div>',
			lafka_cart_drawer_upsell_fragment( array() )['div.lafka-cart-drawer__upsell'],
			'The fragment target always ships so a later refresh can fill it.'
		);
	}
}
