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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
				return array_map( static fn( $id ) => array( 'product_id' => $id ), $this->ids );
			}
			public function is_empty() {
				return empty( $this->ids );
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	public function test_suggests_three_one_tap_products_not_already_in_the_cart(): void {
		$this->cart_with( array( 5 ) );

		self::assertSame( array( 12, 41, 77 ), lafka_cart_drawer_get_upsell_ids() );
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
		self::assertStringContainsString( 'data-product_id="12"', $html );
		self::assertStringContainsString( 'href="?add-to-cart=41"', $html );

		$this->cart_with( array() );
		self::assertSame(
			'<div class="lafka-cart-drawer__upsell" data-lafka-drawer-upsell></div>',
			lafka_cart_drawer_upsell_fragment( array() )['div.lafka-cart-drawer__upsell'],
			'The fragment target always ships so a later refresh can fill it.'
		);
	}
}
