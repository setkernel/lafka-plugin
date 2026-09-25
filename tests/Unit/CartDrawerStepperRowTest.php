<?php
/**
 * GX4 cart-drawer stepper row (incl/woocommerce/lafka-cart-drawer-fragments.php):
 * when the theme opts in (add_theme_support( 'lafka-drawer-stepper' ) or the
 * `lafka_cart_drawer_stepper_enabled` filter) each row carries the options
 * line, a labelled − / count / + stepper and a worded Remove. Without the
 * opt-in the row is today's row, byte for byte.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php';

final class CartDrawerStepperRowTest extends TestCase {

	private bool $supports = true;

	/** @var array<string, callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->supports = true;
		$this->filters  = array();

		$escape = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		$translate = static fn( $text, $domain = '' ) => 'lafka-plugin' === $domain ? $text : "WRONG-DOMAIN({$text})";
		Functions\when( '__' )->alias( $translate );
		Functions\when( 'esc_html__' )->alias( $translate );
		Functions\when( 'esc_attr__' )->alias( $translate );
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text, $domain = '' ) use ( $translate ) {
				echo $translate( $text, $domain ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'wc_get_cart_remove_url' )->alias( static fn( $key ) => 'https://example.test/cart/?remove_item=' . $key . '&_wpnonce=abc' );
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'nonce-' . $action );
		Functions\when( 'current_theme_supports' )->alias( fn( $feature ) => $this->supports && 'lafka-drawer-stepper' === $feature );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
		);
		Functions\when( 'wc_get_formatted_cart_item_data' )->alias(
			static fn( $item, $flat ) => $flat ? implode( "\n", $item['lafka_test_data'] ?? array() ) . ( empty( $item['lafka_test_data'] ) ? '' : "\n" ) : ''
		);
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'cart' => new class() {
					public function get_product_subtotal( $product, $qty ) {
						return '$' . number_format( 9.5 * $qty, 2 );
					}
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function item( int $qty, array $data = array(), int $max = -1 ): array {
		return array(
			'product_id'      => 7,
			'quantity'        => $qty,
			'lafka_test_data' => $data,
			'data'            => new class( $max ) {
				public function __construct( private int $max ) {}
				public function get_name() {
					return 'Pepperoni & Bacon';
				}
				public function get_image() {
					return '<img src="https://example.test/thumb.jpg" alt="">';
				}
				public function get_max_purchase_quantity() {
					return $this->max;
				}
			},
		);
	}

	private static function row( array $item, string $key = 'k1' ): string {
		ob_start();
		lafka_cart_drawer_render_item( $key, $item );
		return (string) ob_get_clean();
	}

	public function test_the_row_names_the_item_its_options_and_a_labelled_stepper(): void {
		$html = self::row( self::item( 2, array( 'Size: Medium', 'Crust: Thin', 'Toppings: Extra cheese' ) ) );

		self::assertStringContainsString( '<h3 class="lafka-cart-drawer__name">Pepperoni & Bacon</h3>', $html, 'The name goes through wp_kses_post like the classic row.' );
		self::assertStringContainsString( '<p class="lafka-cart-drawer__details">Size: Medium · Crust: Thin · Toppings: Extra cheese</p>', $html );
		self::assertStringContainsString( 'role="group" aria-label="Quantity of Pepperoni &amp; Bacon"', $html );
		self::assertMatchesRegularExpression( '#<button type="button" class="lafka-cart-drawer__step lafka-cart-drawer__step--less" data-lafka-qty-step="-1" aria-label="One less Pepperoni &amp; Bacon">#', $html );
		self::assertMatchesRegularExpression( '#<output class="lafka-cart-drawer__qty" aria-live="polite">2</output>#', $html );
		self::assertMatchesRegularExpression( '#<button type="button" class="lafka-cart-drawer__step lafka-cart-drawer__step--more" data-lafka-qty-step="1" aria-label="One more Pepperoni &amp; Bacon">#', $html );
		self::assertStringContainsString( 'data-nonce="nonce-lafka-cart-qty"', $html );
		self::assertStringContainsString( '$19.00', $html );
		self::assertStringNotContainsString( 'WRONG-DOMAIN', $html );
	}

	public function test_remove_is_worded_and_keeps_the_woocommerce_ajax_hook(): void {
		$html = self::row( self::item( 1 ) );

		self::assertMatchesRegularExpression(
			'#<a href="https://example.test/cart/\?remove_item=k1&_wpnonce=abc" class="lafka-cart-drawer__remove remove_from_cart_button"[^>]* data-cart_item_key="k1" aria-label="Remove Pepperoni &amp; Bacon from cart">Remove</a>#',
			$html
		);
	}

	public function test_no_options_line_for_a_plain_item(): void {
		self::assertStringNotContainsString( 'lafka-cart-drawer__details', self::row( self::item( 1 ) ) );
	}

	public function test_minus_stops_at_one_and_plus_stops_at_the_purchase_limit(): void {
		$one = self::row( self::item( 1 ) );
		self::assertMatchesRegularExpression( '#data-lafka-qty-step="-1"[^>]*disabled#', $one, 'Removing is the worded Remove, not a stray minus.' );

		$max = self::row( self::item( 3, array(), 3 ) );
		self::assertMatchesRegularExpression( '#data-lafka-qty-step="1"[^>]*disabled#', $max );
		self::assertDoesNotMatchRegularExpression( '#data-lafka-qty-step="-1"[^>]*disabled#', $max );
	}

	public function test_without_the_opt_in_the_row_is_the_classic_row(): void {
		$item = self::item( 2, array( 'Size: Medium' ) );
		$this->supports = false;
		$html = self::row( $item );

		self::assertStringNotContainsString( 'lafka-cart-drawer__stepper', $html );
		self::assertStringContainsString( '<span class="lafka-cart-drawer__qty">×2</span>', $html );
		self::assertStringContainsString( '>×</a>', $html );
	}

	public function test_a_filter_can_opt_in_without_theme_support(): void {
		$this->supports = false;
		$this->filters['lafka_cart_drawer_stepper_enabled'] = static fn() => true;

		self::assertStringContainsString( 'lafka-cart-drawer__stepper', self::row( self::item( 2 ) ) );
	}
}
