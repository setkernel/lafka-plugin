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

	public function test_the_text_column_is_not_the_drawer_scroll_container(): void {
		$html = self::row( self::item( 2 ) );

		self::assertStringContainsString( '<div class="lafka-cart-drawer__info">', $html, 'O-01: its own class, so it inherits no scroll-container padding/overflow.' );
		self::assertStringNotContainsString( 'lafka-cart-drawer__body', $html );
	}

	public function test_the_stepper_tells_the_script_its_purchase_limit(): void {
		self::assertStringContainsString( 'data-max="3"', self::row( self::item( 2, array(), 3 ) ) );
		self::assertStringNotContainsString( 'data-max', self::row( self::item( 2 ) ), 'No limit, no attribute.' );
	}

	/** A calculated cart line (as WooCommerce stores it after calculate_totals). */
	private static function calculated( array $item, float $subtotal, float $total, array $extra = array() ): array {
		return array_merge(
			$item,
			array(
				'line_subtotal'     => $subtotal,
				'line_subtotal_tax' => 0.0,
				'line_total'        => $total,
				'line_tax'          => 0.0,
			),
			$extra
		);
	}

	private function money(): void {
		Functions\when( 'wc_price' )->alias( static fn( $v ) => '$' . number_format( (float) $v, 2 ) );
		Functions\when( 'wc_format_sale_price' )->alias( static fn( $from, $to ) => '<del>$' . number_format( (float) $from, 2 ) . '</del> <ins>$' . number_format( (float) $to, 2 ) . '</ins>' );
	}

	public function test_line_prices_come_from_the_calculated_line_totals(): void {
		$this->money();
		// get_product_subtotal() would say $19.00 (the stub's 9.50 × 2).
		$html = self::row( self::calculated( self::item( 2 ), 17.0, 17.0 ) );

		self::assertStringContainsString( '<span class="lafka-cart-drawer__price">$17.00</span>', $html );
	}

	public function test_a_bogo_line_shows_the_original_struck_through_before_the_price_paid(): void {
		$this->money();
		// Pop $3.99: BOGO blends the unit price (one of one unit at 50%) → $2.00.
		$item = self::calculated(
			self::item( 1 ),
			2.0,
			2.0,
			array(
				'_bogo_50'             => true,
				'_bogo_original_price' => 3.99,
			)
		);

		self::assertStringContainsString( '<del>$3.99</del> <ins>$2.00</ins>', self::row( $item ) );
	}

	public function test_a_coupon_discount_shows_on_the_line_too(): void {
		$this->money();

		self::assertStringContainsString( '<del>$20.00</del> <ins>$18.00</ins>', self::row( self::calculated( self::item( 2 ), 20.0, 18.0 ) ) );
	}

	public function test_prices_including_tax_add_the_line_taxes(): void {
		$this->money();
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'cart' => new class() {
					public function display_prices_including_tax() {
						return true;
					}
				},
			)
		);
		$item = self::calculated(
			self::item( 1 ),
			10.0,
			10.0,
			array(
				'line_subtotal_tax' => 1.4,
				'line_tax'          => 1.4,
			)
		);

		self::assertStringContainsString( '$11.40', self::row( $item ) );
	}
}
