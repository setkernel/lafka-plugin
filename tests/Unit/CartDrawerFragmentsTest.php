<?php
/**
 * Cart drawer rendering: the line-item rows and the subtotal + free-delivery
 * block, as the theme partial (initial render) and the
 * woocommerce_add_to_cart_fragments refresh both emit them.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php';
require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-free-delivery.php';
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class CartDrawerFragmentsTest extends TestCase {

	private float $option_threshold = 0.0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->option_threshold = 0.0;

		$escape = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		// Translation stubs that flag any string outside the plugin's text domain.
		$translate = static fn( $text, $domain = '' ) => 'lafka-plugin' === $domain ? $text : "WRONG-DOMAIN({$text})";
		Functions\when( '__' )->alias( $translate );
		Functions\when( 'esc_html__' )->alias( $translate );
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text, $domain = '' ) use ( $translate ) {
				echo $translate( $text, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'wc_price' )->alias( static fn( $amount ) => sprintf( '$%.2f', $amount ) );
		Functions\when( 'wc_get_cart_remove_url' )->alias( static fn( $key ) => 'https://example.test/cart/?remove_item=' . $key . '&_wpnonce=abc' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'lafka_free_delivery_threshold' === $key ? $this->option_threshold : $default
		);
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = false ) => $default );
		// No theme opted in to the GX4 stepper row: today's row.
		Functions\when( 'current_theme_supports' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, array{0: string, 1: int, 2: string}> $lines key => [ name, qty, line price ]
	 */
	private function use_cart( array $lines, float $contents_total ): void {
		$items = array();
		foreach ( $lines as $key => $line ) {
			$items[ $key ] = array(
				'product_id' => 7,
				'quantity'   => $line[1],
				'data'       => new class( $line[0], $line[2] ) {
					public function __construct( private string $name, public string $line_price ) {}
					public function get_name() {
						return $this->name;
					}
					public function get_image() {
						return '<img src="https://example.test/thumb.jpg" alt="">';
					}
				},
			);
		}
		$cart = new class( $items, $contents_total ) {
			public function __construct( private array $items, private float $total ) {}
			public function get_cart() {
				return $this->items;
			}
			public function is_empty() {
				return empty( $this->items );
			}
			public function get_cart_contents_total() {
				return $this->total;
			}
			public function get_product_subtotal( $product, $qty ) {
				return $product->line_price;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	private static function capture( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_refresh_rows_match_the_initial_render_and_keep_the_wc_remove_link(): void {
		$this->use_cart( array( 'abc123' => array( 'Margherita <b>Large</b>', 2, '$24.00' ) ), 24.0 );

		$fragments = lafka_pdp_cart_drawer_fragments( array( 'div.widget_shopping_cart_content' => 'kept' ) );
		$row       = self::capture( static fn() => lafka_cart_drawer_render_item( 'abc123', \WC()->cart->get_cart()['abc123'] ) );

		self::assertSame( 'kept', $fragments['div.widget_shopping_cart_content'] );
		self::assertSame( '<ul class="lafka-cart-drawer__items">' . $row . '</ul>', $fragments['ul.lafka-cart-drawer__items'] );
		self::assertStringContainsString( 'data-cart-key="abc123"', $row );
		self::assertStringContainsString( '×2', $row );
		self::assertStringContainsString( '$24.00', $row );
		// WooCommerce's add-to-cart.js binds removal to this nonce'd anchor.
		self::assertMatchesRegularExpression(
			'#<a href="https://example.test/cart/\?remove_item=abc123&_wpnonce=abc" class="lafka-cart-drawer__remove remove_from_cart_button"[^>]* data-cart_item_key="abc123" aria-label="Remove Margherita Large from cart">×</a>#',
			$row
		);
		self::assertStringNotContainsString( 'WRONG-DOMAIN', $fragments['ul.lafka-cart-drawer__items'] );
	}

	public function test_empty_cart_refresh_swaps_in_the_empty_state(): void {
		$this->use_cart( array(), 0.0 );

		$items = lafka_pdp_cart_drawer_fragments( array() )['ul.lafka-cart-drawer__items'];

		self::assertStringContainsString( '<li class="lafka-cart-drawer__empty"', $items );
		self::assertStringContainsString( 'Your cart is empty', $items );
		self::assertStringContainsString( 'href="https://example.test/menu/"', $items );
		self::assertStringNotContainsString( 'lafka-cart-drawer__item"', $items );
		self::assertStringNotContainsString( 'WRONG-DOMAIN', $items );
	}

	public function test_total_shows_progress_toward_the_threshold_the_shipping_rule_enforces(): void {
		// Set only via the WooCommerce option — the drawer must read the same
		// resolver the free-delivery shipping rule uses.
		$this->option_threshold = 40.0;
		$this->use_cart( array( 'k' => array( 'Wings', 1, '$30.00' ) ), 30.0 );

		$total = lafka_pdp_cart_drawer_fragments( array() )['div.lafka-cart-drawer__total'];

		self::assertFalse( lafka_free_delivery_eligible( 30.0 ) );
		self::assertStringContainsString( 'Subtotal', $total );
		self::assertStringContainsString( 'data-state="below"', $total );
		self::assertStringContainsString( 'data-threshold="40"', $total );
		self::assertStringContainsString( 'data-pct="75"', $total );
		self::assertStringContainsString( 'Add $10.00 more for free delivery!', $total );
		self::assertStringNotContainsString( 'WRONG-DOMAIN', $total );
	}

	public function test_total_marks_free_delivery_unlocked_at_the_threshold(): void {
		$this->option_threshold = 40.0;
		$this->use_cart( array( 'k' => array( 'Wings', 2, '$40.00' ) ), 40.0 );

		$total = self::capture( 'lafka_cart_drawer_render_total' );

		self::assertTrue( lafka_free_delivery_eligible( 40.0 ) );
		self::assertStringContainsString( 'data-state="reached"', $total );
		self::assertStringContainsString( 'Free delivery unlocked', $total );
	}

	public function test_no_free_delivery_progress_when_the_feature_is_off(): void {
		// Unconfigured install: no threshold anywhere → no "free delivery" promise.
		$this->use_cart( array( 'k' => array( 'Wings', 1, '$30.00' ) ), 30.0 );

		$total = self::capture( 'lafka_cart_drawer_render_total' );

		self::assertStringContainsString( 'lafka-cart-drawer__subtotal', $total );
		self::assertStringNotContainsString( 'lafka-fdp', $total );
	}
}
