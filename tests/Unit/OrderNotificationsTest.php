<?php
/**
 * OrderNotificationsTest — locks the admin new-order poller MOVED from the parent
 * theme into the plugin (NX1-08b, audit finding #58).
 *
 * Covers the three things the move must preserve/prove:
 *   - Auth: the AJAX endpoint verifies the `lafka_ajax_nonce` nonce and requires
 *     the `manage_woocommerce` capability before emitting anything.
 *   - HPOS-safe meta reads: branch-routing meta is read through the HPOS-aware
 *     accessor (WC_Order fallback here; delegation to the plugin canonical reader
 *     is locked by OrderNotificationHposMetaTest), and branch routing skips orders
 *     assigned to a different operator.
 *   - State round-trip: the `lafka_last_processed_order_ids` option is read,
 *     stale IDs pruned, the freshly-notified ID appended and written back.
 *
 * Brain Monkey stubs the WP/WC functions the handler calls; no WordPress boot.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.36.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Options;
use Lafka_Order_Notifications;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// OrderUtil stub must load before the source so the HPOS-safe accessor's
// delegation into Lafka_Shipping_Areas (loaded by sibling tests in the full
// suite) resolves instead of fataling on a missing WooCommerce class.
require_once __DIR__ . '/Stubs/wc-orderutil-stub.php';
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-order-notifications.php';

/** Thrown by the wp_die() stub so we can assert the handler bailed. */
final class OrderNotifWpDie extends \Exception {}

/** Thrown by the wp_send_json() stub, capturing the emitted payload. */
final class OrderNotifWpSendJson extends \Exception {
	/** @var mixed */
	public $data;
	public function __construct( $data ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json' );
	}
}

final class OrderNotificationsTest extends TestCase {

	/** @var array<string,mixed> Backing store for get_option()/update_option(). */
	private array $options = array();

	/** @var array<int,array<string,mixed>> [order_id => [meta_key => value]]. */
	private array $order_meta = array();

	/** @var array<int,array<string,mixed>> [term_id => [meta_key => value]]. */
	private array $term_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array();
		$this->order_meta = array();
		$this->term_meta  = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'update_meta_cache' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'plugins_url' )->justReturn( 'https://lafka.test/wp-content/plugins/lafka-plugin/incl/admin/assets/img.png' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://lafka.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				unset( $autoload );
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_meta' )->alias(
			function ( $term_id, $key, $single = true ) {
				unset( $single );
				return $this->term_meta[ $term_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->make_order( (int) $id );
			}
		);
		// Legacy (non-HPOS) meta path used by Lafka_Shipping_Areas::get_order_meta_backward_compatible().
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = true ) {
				unset( $single );
				return $this->order_meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'wp_get_attachment_thumb_url' )->justReturn( 'https://lafka.test/branch.png' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->reset_lafka_options_static_state();
		parent::tearDown();
	}

	/**
	 * Minimal WC_Order stand-in exposing get_meta() over a fixed meta map.
	 *
	 * @param int $id Order ID.
	 * @return object
	 */
	private function make_order( int $id ) {
		$meta = $this->order_meta[ $id ] ?? array();
		return new class( $meta ) {
			/** @var array<string,mixed> */
			private array $meta;
			public function __construct( array $meta ) {
				$this->meta = $meta;
			}
			public function get_meta( $key, $single = true ) {
				unset( $single );
				return $this->meta[ $key ] ?? '';
			}
		};
	}

	// ─── Auth ────────────────────────────────────────────────────────────────

	public function test_ajax_handler_verifies_nonce(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'lafka_ajax_nonce', 'security' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new OrderNotifWpDie();
			}
		);

		$this->expectException( OrderNotifWpDie::class );
		Lafka_Order_Notifications::ajax_new_orders_notification();
	}

	public function test_ajax_handler_denies_non_manager(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_send_json' )->never();
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new OrderNotifWpDie();
			}
		);

		$this->expectException( OrderNotifWpDie::class );
		Lafka_Order_Notifications::ajax_new_orders_notification();
	}

	public function test_ajax_handler_emits_json_for_manager(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wp_send_json' )->alias(
			static function ( $data ) {
				throw new OrderNotifWpSendJson( $data );
			}
		);

		try {
			Lafka_Order_Notifications::ajax_new_orders_notification();
			$this->fail( 'wp_send_json() was not called.' );
		} catch ( OrderNotifWpSendJson $e ) {
			$this->assertSame( '', $e->data, 'No processing orders → empty notification.' );
		}
	}

	// ─── State round-trip ─────────────────────────────────────────────────────

	public function test_no_processing_orders_returns_empty_and_writes_empty_state(): void {
		Functions\when( 'wc_get_orders' )->justReturn( array() );

		$result = Lafka_Order_Notifications::compute_notification();

		$this->assertSame( '', $result );
		$this->assertSame( '[]', $this->options[ Lafka_Order_Notifications::STATE_OPTION ] );
	}

	public function test_first_new_order_is_notified_and_appended_to_state(): void {
		$this->options[ Lafka_Order_Notifications::STATE_OPTION ] = '';
		Functions\when( 'wc_get_orders' )->justReturn( array( 101 ) );

		$result = Lafka_Order_Notifications::compute_notification();

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '#101', $result['body'] );
		$this->assertStringContainsString( 'post=101', $result['url'] );

		$state = json_decode( $this->options[ Lafka_Order_Notifications::STATE_OPTION ], true );
		$this->assertContains( 101, $state );
	}

	public function test_already_notified_order_is_skipped(): void {
		$this->options[ Lafka_Order_Notifications::STATE_OPTION ] = json_encode( array( 101 ) );
		Functions\when( 'wc_get_orders' )->justReturn( array( 101 ) );

		$result = Lafka_Order_Notifications::compute_notification();

		$this->assertSame( '', $result, 'An already-notified order must not re-fire.' );
		$state = json_decode( $this->options[ Lafka_Order_Notifications::STATE_OPTION ], true );
		$this->assertContains( 101, $state, 'Still-processing notified IDs are retained.' );
	}

	public function test_stale_notified_ids_are_pruned(): void {
		// 999 was notified but is no longer processing; 101 is new.
		$this->options[ Lafka_Order_Notifications::STATE_OPTION ] = json_encode( array( 999 ) );
		Functions\when( 'wc_get_orders' )->justReturn( array( 101 ) );

		Lafka_Order_Notifications::compute_notification();

		$state = json_decode( $this->options[ Lafka_Order_Notifications::STATE_OPTION ], true );
		$this->assertNotContains( 999, $state, 'IDs no longer processing must be pruned.' );
		$this->assertContains( 101, $state );
	}

	// ─── Branch routing (HPOS-safe meta reads) ─────────────────────────────────

	public function test_order_for_another_branch_operator_is_skipped(): void {
		$this->order_meta[201] = array( 'lafka_selected_branch_id' => 55 );
		$this->term_meta[55]   = array( 'lafka_branch_user' => 99 );
		Functions\when( 'wc_get_orders' )->justReturn( array( 201 ) );
		Functions\when( 'get_current_user_id' )->justReturn( 42 ); // not the assigned operator

		$result = Lafka_Order_Notifications::compute_notification();

		$this->assertSame( '', $result, 'A branch-assigned order must not notify a different operator.' );
	}

	public function test_order_for_assigned_operator_is_notified_with_branch_title(): void {
		$this->order_meta[201] = array( 'lafka_selected_branch_id' => 55 );
		$this->term_meta[55]   = array( 'lafka_branch_user' => 99 );
		Functions\when( 'wc_get_orders' )->justReturn( array( 201 ) );
		Functions\when( 'get_current_user_id' )->justReturn( 99 ); // the assigned operator
		Functions\when( 'get_term' )->justReturn( (object) array( 'name' => 'Downtown' ) );

		$result = Lafka_Order_Notifications::compute_notification();

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Downtown', $result['title'] );
		$this->assertStringContainsString( '#201', $result['body'] );
	}

	public function test_order_with_no_branch_notifies_any_manager(): void {
		$this->order_meta[301] = array(); // no branch assignment
		Functions\when( 'wc_get_orders' )->justReturn( array( 301 ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$result = Lafka_Order_Notifications::compute_notification();

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '#301', $result['body'] );
	}

	// ─── Enable gate ───────────────────────────────────────────────────────────

	public function test_is_enabled_reads_shared_lafka_option(): void {
		$this->reset_lafka_options_static_state();
		$this->options['lafka'] = array( 'order_notifications' => '1' );
		$this->assertTrue( Lafka_Order_Notifications::is_enabled() );

		$this->reset_lafka_options_static_state();
		$this->options['lafka'] = array( 'order_notifications' => '0' );
		$this->assertFalse( Lafka_Order_Notifications::is_enabled() );
	}

	public function test_is_enabled_honours_filter_override(): void {
		$this->reset_lafka_options_static_state();
		$this->options['lafka'] = array( 'order_notifications' => '0' );
		Functions\when( 'apply_filters' )->justReturn( true ); // force-on regardless of option

		$this->assertTrue( Lafka_Order_Notifications::is_enabled() );
	}

	// ─── Enqueue / dialog gating ───────────────────────────────────────────────

	public function test_enqueue_poller_bails_without_woocommerce(): void {
		// WooCommerce class is absent in the unit harness → should_run() is false.
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		Lafka_Order_Notifications::enqueue_poller( 'index.php' );
		$this->assertTrue( true );
	}

	public function test_permission_dialog_outputs_nothing_without_woocommerce(): void {
		$this->expectOutputString( '' );
		Lafka_Order_Notifications::render_permission_dialog();
	}

	// ─── Wiring ────────────────────────────────────────────────────────────────

	public function test_constants_preserve_theme_contract(): void {
		$this->assertSame( 'lafka_new_orders_notification', Lafka_Order_Notifications::AJAX_ACTION );
		$this->assertSame( 'lafka_last_processed_order_ids', Lafka_Order_Notifications::STATE_OPTION );
		$this->assertSame( 'lafka_ajax_nonce', Lafka_Order_Notifications::NONCE_ACTION );
	}

	public function test_class_registers_the_ajax_action(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-order-notifications.php' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_' . self::AJAX_ACTION", $src );
	}

	public function test_main_plugin_requires_the_class(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/admin/class-lafka-order-notifications.php', $main );
	}

	private function reset_lafka_options_static_state(): void {
		if ( ! class_exists( Lafka_Options::class ) ) {
			return;
		}
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
