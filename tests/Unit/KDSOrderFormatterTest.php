<?php
/**
 * KDSOrderFormatterTest — unit tests for Lafka_KDS_Order_Formatter.
 *
 * The KDS frontend depends on the exact shape this class returns; a future
 * refactor that drops a key (e.g. `currency_symbol` or `delivery_address`)
 * silently breaks the kitchen tablet without any PHP error. These tests pin
 * the shape and exercise the conditionals (delivery vs pickup address,
 * scheduled vs ASAP, paid online vs cash, ETA present vs absent).
 *
 * @package Lafka_Kitchen_Display
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_KDS_Order_Formatter;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/lafka-kitchen-display-stub.php';
require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-order-formatter.php';

final class KDSOrderFormatterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'Pizzas' ) );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '&#36;' );

		\Lafka_Kitchen_Display::$type = 'pickup';
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Mockery double for WC_Order with the fields the formatter reads.
	 *
	 * @param array<string, mixed> $overrides Per-test field overrides.
	 */
	private function build_order( array $overrides = array() ): \Mockery\MockInterface {
		$defaults = array(
			'id'                  => 42,
			'order_number'        => '42',
			'status'              => 'processing',
			'date_created_ts'     => 1700000000,
			'payment_method'      => 'stripe',
			'first_name'          => 'Ada',
			'last_name'           => 'Lovelace',
			'phone'               => '555-0100',
			'customer_note'       => '',
			'total'               => '24.00',
			'currency'            => 'USD',
			'shipping_address_1'  => '',
			'shipping_address_2'  => '',
			'shipping_city'       => '',
			'shipping_state'      => '',
			'shipping_postcode'   => '',
			'meta'                => array(),
			'items'               => array(),
		);
		$cfg = array_replace( $defaults, $overrides );

		$date = Mockery::mock( 'WC_DateTime' );
		$date->shouldReceive( 'getTimestamp' )->andReturn( $cfg['date_created_ts'] );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $cfg['id'] );
		$order->shouldReceive( 'get_order_number' )->andReturn( $cfg['order_number'] );
		$order->shouldReceive( 'get_status' )->andReturn( $cfg['status'] );
		$order->shouldReceive( 'get_date_created' )->andReturn( $date );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $cfg['payment_method'] );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( $cfg['first_name'] );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( $cfg['last_name'] );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( $cfg['phone'] );
		$order->shouldReceive( 'get_customer_note' )->andReturn( $cfg['customer_note'] );
		$order->shouldReceive( 'get_total' )->andReturn( $cfg['total'] );
		$order->shouldReceive( 'get_currency' )->andReturn( $cfg['currency'] );
		$order->shouldReceive( 'get_shipping_address_1' )->andReturn( $cfg['shipping_address_1'] );
		$order->shouldReceive( 'get_shipping_address_2' )->andReturn( $cfg['shipping_address_2'] );
		$order->shouldReceive( 'get_shipping_city' )->andReturn( $cfg['shipping_city'] );
		$order->shouldReceive( 'get_shipping_state' )->andReturn( $cfg['shipping_state'] );
		$order->shouldReceive( 'get_shipping_postcode' )->andReturn( $cfg['shipping_postcode'] );
		$order->shouldReceive( 'get_items' )->andReturn( $cfg['items'] );

		$meta = $cfg['meta'];
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);

		return $order;
	}

	public function test_format_includes_all_top_level_keys(): void {
		$order  = $this->build_order();
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );

		$expected_keys = array(
			'id', 'number', 'status', 'date_created', 'order_type',
			'is_paid_online', 'payment_label', 'customer_name', 'customer_phone',
			'items', 'customer_note', 'scheduled', 'eta', 'eta_minutes',
			'accepted_at', 'total', 'currency_symbol', 'delivery_address',
			'special_instructions', 'allergen_info',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing key: {$key}" );
		}
	}

	public function test_paid_online_for_stripe(): void {
		$order  = $this->build_order( array( 'payment_method' => 'stripe' ) );
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertTrue( $result['is_paid_online'] );
		$this->assertSame( 'Paid Online', $result['payment_label'] );
	}

	public function test_cash_on_delivery_not_paid_online(): void {
		$order  = $this->build_order( array( 'payment_method' => 'cod' ) );
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertFalse( $result['is_paid_online'] );
		$this->assertSame( 'Cash on Delivery', $result['payment_label'] );
	}

	public function test_empty_payment_method_treated_as_cash(): void {
		// Manual orders sometimes have no payment method set.
		$order  = $this->build_order( array( 'payment_method' => '' ) );
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertFalse( $result['is_paid_online'] );
	}

	public function test_delivery_address_blank_for_pickup(): void {
		\Lafka_Kitchen_Display::$type = 'pickup';
		$order  = $this->build_order(
			array(
				'shipping_address_1' => '123 Main St',
				'shipping_city'      => 'Townsville',
			)
		);
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertSame( '', $result['delivery_address'], 'Pickup orders should not leak shipping address to KDS.' );
	}

	public function test_delivery_address_assembled_for_delivery(): void {
		\Lafka_Kitchen_Display::$type = 'delivery';
		$order  = $this->build_order(
			array(
				'shipping_address_1' => '123 Main St',
				'shipping_address_2' => '',
				'shipping_city'      => 'Townsville',
				'shipping_state'     => 'ON',
				'shipping_postcode'  => 'A1A 1A1',
			)
		);
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		// Empty address_2 must NOT introduce ", , " — array_filter strips empties.
		$this->assertSame( '123 Main St, Townsville, ON, A1A 1A1', $result['delivery_address'] );
	}

	public function test_scheduled_string_combined_when_both_meta_present(): void {
		$order = $this->build_order(
			array(
				'meta' => array(
					'lafka_order_date' => '2026-05-01',
					'lafka_order_time' => '18:30',
				),
			)
		);
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertSame( '2026-05-01 18:30', $result['scheduled'] );
	}

	public function test_scheduled_blank_for_asap_orders(): void {
		$order  = $this->build_order(); // no scheduling meta
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertSame( '', $result['scheduled'] );
	}

	public function test_eta_keys_null_when_meta_absent(): void {
		$order  = $this->build_order();
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertNull( $result['eta'] );
		$this->assertNull( $result['eta_minutes'] );
		$this->assertNull( $result['accepted_at'] );
	}

	public function test_eta_keys_int_when_meta_present(): void {
		$order = $this->build_order(
			array(
				'meta' => array(
					'_lafka_kds_eta'         => '1700000045',
					'_lafka_kds_eta_minutes' => '45',
					'_lafka_kds_accepted_at' => '1700000000',
				),
			)
		);
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertSame( 1700000045, $result['eta'] );
		$this->assertSame( 45, $result['eta_minutes'] );
		$this->assertSame( 1700000000, $result['accepted_at'] );
	}

	public function test_currency_symbol_decoded_to_utf8(): void {
		// WC returns &#36; for USD; KDS JS uses textContent which would render
		// the entity literally. Formatter must decode.
		$order  = $this->build_order();
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertSame( '$', $result['currency_symbol'] );
	}

	public function test_customer_name_trimmed(): void {
		// First+last with empty middle parts shouldn't leak double-spaces.
		$order  = $this->build_order(
			array(
				'first_name' => 'Ada',
				'last_name'  => '',
			)
		);
		$result = ( new Lafka_KDS_Order_Formatter() )->format( $order );
		$this->assertSame( 'Ada', $result['customer_name'] );
	}
}
