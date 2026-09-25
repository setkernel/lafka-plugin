<?php
/**
 * GX4 drawer-extras wording (incl/woocommerce/lafka-cart-drawer-upsell.php):
 * a theme can reword the upsell heading and the add button and give each row
 * a short note, through filters; unfiltered output stays as before.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UpsellCopyFilterTest extends TestCase {

	/** @var array<string, callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->filters = array();
		// No deals category configured (O-23 exclusion reads these).
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'get_term_by' )->justReturn( false );
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-upsell-row.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-upsell.php';

		$escape = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/p/' . $id );
		Functions\when( 'get_the_post_thumbnail' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( array( 12 ) );
		Functions\when( 'wc_get_products' )->justReturn( array() );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
		);
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => new class( (int) $id ) {
				public function __construct( private int $id ) {}
				public function get_id() {
					return $this->id;
				}
				public function is_visible() {
					return true;
				}
				public function is_purchasable() {
					return true;
				}
				public function is_in_stock() {
					return true;
				}
				public function is_type( $type ) {
					return false;
				}
				public function get_name() {
					return 'Garlic Fingers';
				}
				public function get_price_html() {
					return '$6.00';
				}
				public function add_to_cart_url() {
					return '?add-to-cart=' . $this->id;
				}
			}
		);
		$cart = new class() {
			public function get_cart() {
				return array( array( 'product_id' => 5 ) );
			}
			public function is_empty() {
				return false;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function html(): string {
		return lafka_cart_drawer_upsell_fragment( array() )['div.lafka-cart-drawer__upsell'];
	}

	public function test_unfiltered_copy_is_unchanged(): void {
		$html = self::html();

		self::assertStringContainsString( '<p class="lafka-cart-drawer__upsell-heading">Complete your meal</p>', $html );
		self::assertMatchesRegularExpression( '#ajax_add_to_cart"[^>]*>\s*\+ Add\s*</a>#', $html );
		self::assertStringNotContainsString( 'lafka-cart-drawer__upsell-note', $html );
	}

	public function test_heading_button_and_row_note_are_filterable(): void {
		$this->filters['lafka_cart_drawer_upsell_heading']   = static fn() => 'Add a little extra?';
		$this->filters['lafka_cart_drawer_upsell_add_label'] = static fn( $label, $product ) => 'Add';
		$this->filters['lafka_cart_drawer_upsell_row_note']  = static fn( $note, $product ) => 12 === $product->get_id() ? 'Six <b>fingers</b> & dip' : $note;

		$html = self::html();

		self::assertStringContainsString( '<p class="lafka-cart-drawer__upsell-heading">Add a little extra?</p>', $html );
		self::assertMatchesRegularExpression( '#ajax_add_to_cart"[^>]*>\s*Add\s*</a>#', $html );
		self::assertStringContainsString( '<span class="lafka-cart-drawer__upsell-note">Six fingers &amp; dip</span>', $html, 'The note is plain text.' );
	}

	public function test_an_empty_heading_filter_keeps_the_default(): void {
		$this->filters['lafka_cart_drawer_upsell_heading'] = static fn() => '   ';

		self::assertStringContainsString( '>Complete your meal</p>', self::html() );
	}
}
