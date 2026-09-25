<?php
/**
 * GX4 drawer quantity endpoint (incl/woocommerce/lafka-cart-drawer-qty.php):
 * wc-ajax=lafka_cart_set_qty changes one cart line's quantity and answers
 * with WooCommerce's refreshed fragments. It needs the lafka-cart-qty nonce,
 * only exists while the theme opts in to the stepper, only touches a line in
 * the visitor's own cart, and respects WooCommerce's own quantity rules
 * (sold individually, stock limit, woocommerce_update_cart_validation).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WC_AJAX' ) ) {
		/** Records the fragment refresh the endpoint ends with. */
		class WC_AJAX { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			public static int $refreshed = 0;
			public static function get_refreshed_fragments() {
				++self::$refreshed;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use LafkaPlugin\Tests\Unit\Support\Hooks;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	require_once __DIR__ . '/Support/Hooks.php';

	final class CartDrawerQtyEndpointTest extends TestCase {

		/** @var array<string, array> */
		public array $cart = array();

		private bool $nonce_ok = true;

		private bool $supports = true;

		/** @var array<string, callable> */
		private array $filters = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$_POST              = array();
			$this->nonce_ok     = true;
			$this->supports     = true;
			$this->filters      = array();
			\WC_AJAX::$refreshed = 0;
			$this->cart         = array(
				'k1' => array(
					'quantity' => 2,
					'data'     => self::product( -1, false ),
				),
				'solo' => array(
					'quantity' => 1,
					'data'     => self::product( -1, true ),
				),
				'low' => array(
					'quantity' => 2,
					'data'     => self::product( 3, false ),
				),
			);

			Functions\when( '__' )->returnArg();
			Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
			Functions\when( 'check_ajax_referer' )->alias( fn( $action, $field, $die ) => 'lafka-cart-qty' === $action && 'nonce' === $field && false === $die && $this->nonce_ok ? 1 : false );
			Functions\when( 'current_theme_supports' )->alias( fn( $feature ) => $this->supports && 'lafka-drawer-stepper' === $feature );
			Functions\when( 'apply_filters' )->alias(
				fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
			);
			Functions\when( 'wc_get_notices' )->justReturn( array() );
			Functions\when( 'wc_clear_notices' )->justReturn( null );
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data, $status = null ) {
					throw new RuntimeException( (string) ( $data['code'] ?? '' ) . '|' . $status );
				}
			);
			$cart = new class( $this ) {
				public function __construct( private CartDrawerQtyEndpointTest $test ) {}
				public function get_cart_item( $key ) {
					return $this->test->cart[ $key ] ?? array();
				}
				public function set_quantity( $key, $qty, $refresh = true ) {
					if ( 0 === $qty ) {
						unset( $this->test->cart[ $key ] );
					} else {
						$this->test->cart[ $key ]['quantity'] = $qty;
					}
					return true;
				}
			};
			Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );

			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php';
			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-qty.php';
		}

		protected function tearDown(): void {
			$_POST = array();
			Monkey\tearDown();
			parent::tearDown();
		}

		private static function product( int $max, bool $solo ): object {
			return new class( $max, $solo ) {
				public function __construct( private int $max, private bool $solo ) {}
				public function is_sold_individually() {
					return $this->solo;
				}
				public function get_max_purchase_quantity() {
					return $this->solo ? 1 : $this->max;
				}
			};
		}

		private function post( string $key, $qty ): string {
			$_POST = array(
				'cart_item_key' => $key,
				'quantity'      => (string) $qty,
				'nonce'         => 'n',
			);
			try {
				lafka_cart_drawer_set_qty();
			} catch ( RuntimeException $e ) {
				return $e->getMessage();
			}
			return 'ok';
		}

		public function test_sets_the_quantity_and_answers_with_refreshed_fragments(): void {
			self::assertSame( 'ok', $this->post( 'k1', 3 ) );

			self::assertSame( 3, $this->cart['k1']['quantity'] );
			self::assertSame( 1, \WC_AJAX::$refreshed );
		}

		public function test_zero_removes_the_line(): void {
			self::assertSame( 'ok', $this->post( 'k1', 0 ) );

			self::assertArrayNotHasKey( 'k1', $this->cart );
		}

		public function test_a_bad_nonce_changes_nothing(): void {
			$this->nonce_ok = false;

			self::assertSame( 'invalid_nonce|403', $this->post( 'k1', 5 ) );
			self::assertSame( 2, $this->cart['k1']['quantity'] );
			self::assertSame( 0, \WC_AJAX::$refreshed );
		}

		public function test_only_a_line_in_this_cart_can_change(): void {
			self::assertSame( 'unknown_item|404', $this->post( 'someone-else', 1 ) );
		}

		public function test_quantity_must_be_a_whole_non_negative_number(): void {
			self::assertSame( 'invalid_quantity|400', $this->post( 'k1', '-1' ) );
			self::assertSame( 'invalid_quantity|400', $this->post( 'k1', 'lots' ) );
			self::assertSame( 2, $this->cart['k1']['quantity'] );
		}

		public function test_woocommerce_quantity_rules_hold(): void {
			self::assertSame( 'sold_individually|400', $this->post( 'solo', 2 ) );
			self::assertSame( 'not_enough_stock|400', $this->post( 'low', 4 ) );
			self::assertSame( 'ok', $this->post( 'low', 3 ) );

			$this->filters['woocommerce_update_cart_validation'] = static fn() => false;
			self::assertSame( 'invalid_quantity|400', $this->post( 'k1', 4 ) );
			self::assertSame( 2, $this->cart['k1']['quantity'] );
		}

		public function test_the_endpoint_is_off_unless_the_theme_opts_in(): void {
			$this->supports = false;

			self::assertSame( 'stepper_disabled|404', $this->post( 'k1', 3 ) );
			self::assertSame( 2, $this->cart['k1']['quantity'] );
		}

		public function test_registers_the_wc_ajax_endpoint_and_the_script(): void {
			Hooks::reset();
			lafka_cart_drawer_qty_init();

			self::assertContains( 'wc_ajax_lafka_cart_set_qty -> lafka_cart_drawer_set_qty', Hooks::registered() );
			self::assertContains( 'wp_enqueue_scripts -> lafka_cart_drawer_qty_enqueue', Hooks::registered() );
		}

		public function test_the_script_loads_only_for_a_theme_that_opted_in(): void {
			$enqueued = array();
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/wp-content/plugins/lafka-plugin/' . $path );
			Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
			Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
			Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( $handle ) use ( &$enqueued ) {
					$enqueued[] = $handle;
				}
			);
			$localized = array();
			Functions\when( 'wp_localize_script' )->alias(
				static function ( $handle, $name, $data ) use ( &$localized ) {
					$localized[ $name ] = $data;
				}
			);

			$this->supports = false;
			lafka_cart_drawer_qty_enqueue();
			self::assertSame( array(), $enqueued );

			$this->supports = true;
			lafka_cart_drawer_qty_enqueue();
			self::assertSame( array( 'lafka-cart-drawer-qty' ), $enqueued );
			self::assertStringContainsString( 'lafka_cart_set_qty', $localized['lafkaCartQty']['url'] );
			self::assertArrayNotHasKey( 'nonce', $localized['lafkaCartQty'], 'No per-visitor value in page-cacheable config; the nonce rides the refreshed rows.' );
		}
	}
}
