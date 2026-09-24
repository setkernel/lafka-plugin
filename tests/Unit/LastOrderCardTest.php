<?php
/**
 * Last-order card: the signed cookie written on the order-received page, and
 * the reorder endpoint that trusts only a server-signed cookie (guests) or an
 * order the logged-in customer owns.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WC_AJAX' ) ) {
		/** Records the fragment refresh the reorder endpoint ends with. */
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
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	final class LastOrderCardTest extends TestCase {

		/** @var array<int, array{0: int, 1: int, 2: int, 3: array}> add_to_cart() calls. */
		private array $added = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$_POST   = array();
			$_GET    = array();
			$_COOKIE = array();
			\WC_AJAX::$refreshed = 0;
			if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
				define( 'YEAR_IN_SECONDS', 31536000 );
			}
			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-last-order-card.php';

			Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
			Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
			Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
			Functions\when( 'check_ajax_referer' )->justReturn( 1 );
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data, $status = null ) {
					throw new RuntimeException( (string) ( $data['message'] ?? '' ) . ' ' . $status );
				}
			);
			$cart = new class( $this ) {
				public function __construct( private $test ) {}
				public function add_to_cart( $product_id, $qty, $variation_id, $variation ) {
					return $this->test->record_add( $product_id, $qty, $variation_id, $variation );
				}
			};
			Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
		}

		protected function tearDown(): void {
			$_POST   = array();
			$_GET    = array();
			$_COOKIE = array();
			Monkey\tearDown();
			parent::tearDown();
		}

		/** Cart double callback. */
		public function record_add( $product_id, $qty, $variation_id, $variation ): bool {
			$this->added[] = array( $product_id, $qty, $variation_id, $variation );
			return true;
		}

		private function order( int $id, array $lines, int $customer = 0, string $key = 'wc_order_abc' ) {
			$items = array();
			foreach ( $lines as $line ) {
				$item = Mockery::mock();
				$item->shouldReceive( 'get_product_id' )->andReturn( $line[0] );
				$item->shouldReceive( 'get_variation_id' )->andReturn( $line[1] );
				$item->shouldReceive( 'get_quantity' )->andReturn( $line[2] );
				$item->shouldReceive( 'get_name' )->andReturn( 'Item ' . $line[0] );
				$item->shouldReceive( 'get_meta_data' )->andReturn( array( (object) array( 'key' => 'pa_size', 'value' => 'large' ) ) );
				$items[] = $item;
			}
			$order = Mockery::mock( 'WC_Order' );
			$order->shouldReceive( 'get_items' )->andReturn( $items );
			$order->shouldReceive( 'get_id' )->andReturn( $id );
			$order->shouldReceive( 'get_customer_id' )->andReturn( $customer );
			$order->shouldReceive( 'get_order_key' )->andReturn( $key );
			return $order;
		}

		private function reorder(): string {
			try {
				lafka_pdp_reorder_ajax();
				return 'ok';
			} catch ( RuntimeException $e ) {
				return trim( $e->getMessage() );
			}
		}

		public function test_cookie_carries_a_signature_over_the_order_and_its_items(): void {
			$value = json_decode( (string) lafka_pdp_last_order_cookie_value( $this->order( 42, array( array( 15, 16, 2 ) ) ) ), true );

			$this->assertSame( 42, $value['order_id'] );
			$this->assertSame( array( 'attribute_pa_size' => 'large' ), $value['items'][0]['variation'] );
			$this->assertSame( lafka_pdp_recent_order_signature( 42, $value['items'] ), $value['sig'] );
			$value['items'][0]['qty'] = 20;
			$this->assertNotSame( lafka_pdp_recent_order_signature( 42, $value['items'] ), $value['sig'], 'Editing an item must break the signature.' );
		}

		public function test_cookie_is_http_only_and_same_site(): void {
			Functions\when( 'is_ssl' )->justReturn( true );
			$options = lafka_pdp_last_order_cookie_options();

			$this->assertTrue( $options['httponly'] );
			$this->assertSame( 'Lax', $options['samesite'] );
			$this->assertTrue( $options['secure'] );
		}

		public function test_order_received_page_is_recognised_only_with_the_orders_key(): void {
			Functions\when( 'is_wc_endpoint_url' )->justReturn( true );
			Functions\when( 'get_query_var' )->justReturn( '42' );
			Functions\when( 'wc_clean' )->returnArg();
			$order = $this->order( 42, array( array( 15, 0, 1 ) ) );
			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_GET['key'] = 'wc_order_abc';
			$this->assertSame( 42, lafka_pdp_confirmed_order_id() );
			$_GET['key'] = 'wc_order_guess';
			$this->assertSame( 0, lafka_pdp_confirmed_order_id() );
		}

		public function test_guest_reorder_rebuilds_the_cart_from_a_signed_cookie_only(): void {
			$items                                = array(
				array(
					'product_id'   => 15,
					'variation_id' => 16,
					'qty'          => 2,
					'name'         => 'Pizza',
					'variation'    => array( 'attribute_pa_size' => '<b>large</b>' ),
				),
			);
			$_COOKIE[ LAFKA_PDP_LAST_ORDER_COOKIE ] = json_encode(
				array(
					'order_id' => 42,
					'items'    => $items,
					'sig'      => lafka_pdp_recent_order_signature( 42, $items ),
				)
			);
			$_POST['order_id']                    = '999'; // Ignored for guests.
			Functions\expect( 'wc_get_order' )->never();

			$this->assertSame( 'ok', $this->reorder() );
			$this->assertSame( array( array( 15, 2, 16, array( 'attribute_pa_size' => 'large' ) ) ), $this->added );
			$this->assertSame( 1, \WC_AJAX::$refreshed );
		}

		public function test_guest_reorder_refuses_a_forged_cookie(): void {
			$_COOKIE[ LAFKA_PDP_LAST_ORDER_COOKIE ] = json_encode(
				array(
					'order_id' => 42,
					'items'    => array( array( 'product_id' => 15 ) ),
					'sig'      => 'forged',
				)
			);
			Functions\expect( 'wc_get_order' )->never();

			$this->assertSame( 'Permission denied 403', $this->reorder() );
			$this->assertSame( array(), $this->added );
		}

		public function test_logged_in_reorder_refuses_someone_elses_order(): void {
			Functions\when( 'is_user_logged_in' )->justReturn( true );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'wc_get_order' )->justReturn( $this->order( 42, array( array( 15, 0, 1 ) ), 8 ) );
			$_POST['order_id'] = '42';

			$this->assertSame( 'Permission denied 403', $this->reorder() );
			$this->assertSame( array(), $this->added );
		}

		public function test_reorder_checks_the_nonce_first(): void {
			Functions\when( 'check_ajax_referer' )->alias(
				static function ( $action, $field ) {
					throw new RuntimeException( "bad nonce for {$action}/{$field}" );
				}
			);

			$this->assertSame( 'bad nonce for lafka_pdp_reorder/nonce', $this->reorder() );
			$this->assertSame( array(), $this->added );
		}
	}
}
