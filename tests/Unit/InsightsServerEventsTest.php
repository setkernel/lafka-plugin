<?php
/**
 * Insights server-side money events (GX2 / B1): add to cart, cart/checkout
 * views (classic + block pages), payment attempt (classic + Store API), order
 * counted once, payment failure classes, and the `lafka_checkout_blocked`
 * listener — including the webhook case where the visit is picked up from
 * the payment attempt.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Insights_DB;
use Lafka_Insights_Server_Events;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';
require_once __DIR__ . '/Support/InsightsHarness.php';

/**
 * Minimal WC_Order double.
 */
final class FakeInsightsOrder {

	/** @var array<string,mixed> */
	public array $meta = array();
	public int $saves  = 0;

	/** @param array<int,int> $product_ids */
	public function __construct( public int $id, public array $product_ids = array( 42 ), public string $via = 'checkout' ) {}

	public function get_id() {
		return $this->id;
	}

	public function get_created_via() {
		return $this->via;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save_meta_data() {
		$this->saves++;
	}

	public function get_items() {
		return array_map(
			static function ( $pid ) {
				return new class( $pid ) {
					public function __construct( private int $pid ) {}
					public function get_product_id() {
						return $this->pid;
					}
				};
			},
			$this->product_ids
		);
	}
}

final class InsightsServerEventsTest extends TestCase {

	use InsightsHarness;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
		Functions\when( 'wc_get_order_notes' )->justReturn( array() );
		Hooks::reset();
		Lafka_Insights_Server_Events::register();
	}

	/**
	 * Fire a hook through the callbacks the module registered (the test
	 * bootstrap records add_action() calls), like do_action() would.
	 *
	 * @param string $hook    Hook.
	 * @param mixed  ...$args Arguments.
	 */
	private function fire( string $hook, ...$args ): void {
		$fired = 0;
		foreach ( $GLOBALS['lafka_test_hooks'] as $registration ) {
			list( $tag, $callback, , $accepted ) = $registration;
			if ( $tag === $hook ) {
				call_user_func_array( $callback, array_slice( $args, 0, (int) $accepted ) );
				$fired++;
			}
		}
		$this->assertGreaterThan( 0, $fired, "Nothing listens on {$hook}." );
	}

	protected function tearDown(): void {
		$this->tear_down_insights();
		parent::tearDown();
	}

	private function session_write(): string {
		$writes = array_values(
			array_filter(
				$this->wpdb->writes(),
				static function ( $q ) {
					return false !== strpos( $q, 'wp_lafka_insights_sessions' );
				}
			)
		);
		$this->assertNotEmpty( $writes, 'Expected a session upsert.' );
		return end( $writes );
	}

	private function counter_writes(): string {
		return implode(
			"\n",
			array_filter(
				$this->wpdb->writes(),
				static function ( $q ) {
					return false !== strpos( $q, 'wp_lafka_insights_daily' );
				}
			)
		);
	}

	public function test_register_hooks_every_money_event_for_both_checkouts(): void {
		$registered = Hooks::registered();
		foreach ( array(
			'woocommerce_add_to_cart -> on_add_to_cart',
			'woocommerce_cart_item_removed -> on_cart_item_removed',
			'template_redirect -> on_template_redirect',
			'woocommerce_checkout_order_processed -> on_checkout_order_processed',
			'woocommerce_store_api_checkout_order_processed -> on_store_api_order_processed',
			'woocommerce_order_status_changed -> on_order_status_changed',
			'woocommerce_order_status_failed -> on_order_failed',
			'lafka_checkout_blocked -> on_checkout_blocked',
		) as $expected ) {
			$this->assertContains( $expected, $registered );
		}
	}

	public function test_add_to_cart_records_the_add_stage_and_the_item(): void {
		Lafka_Insights_Server_Events::on_add_to_cart( 'key', 42, 1 );

		$bits = Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_ADD;
		$this->assertStringContainsString( "UNHEX('", $this->session_write() );
		$this->assertStringContainsString( ",{$bits},", $this->session_write() );
		$this->assertStringContainsString( "'item_add','42',1", $this->counter_writes() );
	}

	public function test_checkout_page_view_counts_on_block_and_classic_pages_alike(): void {
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'WC' )->justReturn(
			(object) array(
				'cart' => new class() {
					public function is_empty() {
						return false;
					}
				},
			)
		);

		Lafka_Insights_Server_Events::on_template_redirect();

		$bits = Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_ADD | Lafka_Insights_DB::STAGE_CART | Lafka_Insights_DB::STAGE_CHECKOUT;
		$this->assertStringContainsString( ",{$bits},", $this->session_write() );
	}

	public function test_empty_cart_or_thank_you_page_records_nothing(): void {
		Functions\when( 'is_wc_endpoint_url' )->alias(
			static function ( $ep ) {
				return 'order-received' === $ep;
			}
		);
		Functions\when( 'is_checkout' )->justReturn( true );
		Lafka_Insights_Server_Events::on_template_redirect();
		$this->assertSame( array(), $this->wpdb->writes() );
	}

	public function test_store_api_payment_attempt_parks_the_visit_for_the_order(): void {
		Lafka_Insights_Server_Events::on_store_api_order_processed( new FakeInsightsOrder( 501 ) );

		$this->assertStringContainsString( ',' . ( Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_ADD | Lafka_Insights_DB::STAGE_CART | Lafka_Insights_DB::STAGE_CHECKOUT | Lafka_Insights_DB::STAGE_PAY_ATTEMPT ) . ',', $this->session_write() );
		$this->assertMatchesRegularExpression( '/^2026-09-24\|[a-f0-9]{32}$/', (string) $this->transients['lafka_ins_o_501'] );
	}

	public function test_order_is_counted_once_against_the_parked_visit_even_from_a_webhook(): void {
		Lafka_Insights_Server_Events::on_checkout_order_processed( 777 );
		$parked = (string) $this->transients['lafka_ins_o_777'];
		$sid    = substr( $parked, 11 );

		// The gateway webhook arrives from another IP / UA the next morning.
		$_SERVER['REMOTE_ADDR']     = '192.0.2.99';
		$_SERVER['HTTP_USER_AGENT'] = 'GatewayWebhook/1.0 (compatible; bot)';
		$this->now                 += 12 * 3600;
		$this->wpdb->queries        = array();

		$order = new FakeInsightsOrder( 777, array( 42, 7 ) );
		Lafka_Insights_Server_Events::on_order_status_changed( 777, 'pending', 'processing', $order );

		$write = $this->session_write();
		$this->assertStringContainsString( "'2026-09-24',UNHEX('{$sid}')", $write, 'Recorded on the visit (and day) that paid.' );
		$this->assertStringContainsString( ',' . ( Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_ORDER ) . ',', $write );
		$this->assertStringContainsString( "'item_order','42',1", $this->counter_writes() );
		$this->assertStringContainsString( "'item_order','7',1", $this->counter_writes() );
		$this->assertSame( '1', $order->meta['_lafka_insights_counted'] );
		$this->assertArrayNotHasKey( 'lafka_ins_o_777', $this->transients, 'The parked visit id is deleted once used.' );

		$this->wpdb->queries = array();
		Lafka_Insights_Server_Events::on_order_status_changed( 777, 'processing', 'completed', $order );
		$this->assertSame( array(), $this->wpdb->writes(), 'processing → completed does not count the order twice.' );
	}

	public function test_admin_created_orders_and_non_placed_statuses_are_ignored(): void {
		Lafka_Insights_Server_Events::on_order_status_changed( 9, 'pending', 'processing', new FakeInsightsOrder( 9, array( 1 ), 'admin' ) );
		Lafka_Insights_Server_Events::on_order_status_changed( 9, 'pending', 'cancelled', new FakeInsightsOrder( 9 ) );
		$this->assertSame( array(), $this->wpdb->writes() );
	}

	public function test_payment_failure_is_classified_from_the_newest_order_note(): void {
		Functions\when( 'wc_get_order_notes' )->justReturn(
			array( (object) array( 'content' => 'Credit card payment failed (Error Code 27: The transaction has been declined because of an AVS mismatch.)' ) )
		);
		Lafka_Insights_Server_Events::on_checkout_order_processed( 88 );
		$this->wpdb->queries = array();

		Lafka_Insights_Server_Events::on_order_failed( 88, new FakeInsightsOrder( 88 ) );

		$write = $this->session_write();
		$this->assertStringContainsString( ',' . ( Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_PAY_FAILED ) . ',', $write );
		$this->assertStringContainsString( "'payment_avs'", $write );
		$this->assertStringContainsString( "'pay_fail','avs',1", $this->counter_writes() );
		$this->assertStringContainsString( "'block','payment_failed',1", $this->counter_writes() );
	}

	public function test_failure_classifier_buckets(): void {
		$this->assertSame( 'avs', Lafka_Insights_Server_Events::classify_payment_failure( 'Declined: AVS mismatch' ) );
		$this->assertSame( 'cvv', Lafka_Insights_Server_Events::classify_payment_failure( 'Card code (CVV2) does not match' ) );
		$this->assertSame( 'declined', Lafka_Insights_Server_Events::classify_payment_failure( 'Card declined: insufficient funds' ) );
		$this->assertSame( 'gateway_error', Lafka_Insights_Server_Events::classify_payment_failure( 'Gateway timeout while processing' ) );
		$this->assertSame( 'other', Lafka_Insights_Server_Events::classify_payment_failure( '' ) );
	}

	public function test_checkout_blocked_listener_records_the_reason_once_per_request(): void {
		$this->fire( 'lafka_checkout_blocked', 'outside_delivery_zone', array( 'order_type' => 'delivery' ) );
		$this->fire( 'lafka_checkout_blocked', 'outside_delivery_zone', array() );

		$sessions = array_filter(
			$this->wpdb->writes(),
			static function ( $q ) {
				return false !== strpos( $q, 'wp_lafka_insights_sessions' );
			}
		);
		$this->assertCount( 1, $sessions, 'A refusal fired repeatedly in one request is recorded once.' );
		$write = $this->session_write();
		$this->assertStringContainsString( ",2,'outside_delivery_zone',", $write, 'block_mask bit + last reason.' );
		$this->assertStringContainsString( 'block_mask = block_mask | VALUES(block_mask)', $write );
		$this->assertStringContainsString( "'block','outside_delivery_zone',1", $this->counter_writes() );
	}

	public function test_reason_bits_cover_known_payment_validation_and_unknown_reasons(): void {
		$this->assertSame( 1, Lafka_Insights_Server_Events::reason_bit( 'store_closed' ) );
		$this->assertSame( 512, Lafka_Insights_Server_Events::reason_bit( 'payment_cvv' ) );
		$this->assertSame( 128, Lafka_Insights_Server_Events::reason_bit( 'validation_billing_phone' ) );
		$this->assertSame( 1 << 30, Lafka_Insights_Server_Events::reason_bit( 'something_new' ) );
		$this->assertSame( 'timeslot_invalid', Lafka_Insights_Server_Events::normalize_reason( 'Timeslot-Invalid' ) );
	}

	public function test_opted_out_requests_write_no_visit(): void {
		$_SERVER['HTTP_SEC_GPC'] = '1';
		Lafka_Insights_Server_Events::on_add_to_cart( 'k', 42 );
		$this->fire( 'lafka_checkout_blocked', 'store_closed', array() );
		$this->assertSame( array(), $this->wpdb->writes() );
	}
}
