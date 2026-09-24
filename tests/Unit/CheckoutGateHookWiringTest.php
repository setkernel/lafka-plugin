<?php
/**
 * Every ordering gate is registered on a hook that can actually block.
 *
 * The gate callbacks are covered behaviourally (TimeslotsBehaviorTest,
 * OrderHoursBehaviorTest, StoreApiParityTest); what they cannot show is the
 * hook each one hangs off, and that choice was the bug twice: the mandatory
 * date/time check sat on woocommerce_checkout_create_order (after validation,
 * so its notice never blocked), and the block-checkout store-closed gate sat on
 * woocommerce_store_api_validate_cart (a hook WooCommerce never fires).
 *
 * Each module's real registration code runs here; the bootstrap records what
 * it hooked.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Order_Hours;
use Lafka_Store_Api;
use Lafka_Timeslots;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Support/Hooks.php';

final class CheckoutGateHookWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'lafka_shipping_areas_datetime' === $key ? array( 'enable_datetime_option' => '1' ) : $default
		);
		require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
		require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
		require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';
		Hooks::reset();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_each_gate_is_registered_on_its_blocking_hook(): void {
		// Timeslots: the constructor wires its hooks (feature enabled).
		$timeslots = ( new ReflectionClass( Lafka_Timeslots::class ) )->newInstanceWithoutConstructor();
		( new \ReflectionMethod( Lafka_Timeslots::class, '__construct' ) )->invoke( $timeslots );

		// Order hours: the gates are wired whether the store is open or not.
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Lafka_Order_Hours::$lafka_order_hours_force_override_check  = true;
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '1';
		( new ReflectionClass( Lafka_Order_Hours::class ) )->newInstanceWithoutConstructor()->handle_shop_status();
		Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
		Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';

		// Store API.
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->justReturn( true );
		Functions\when( 'woocommerce_store_api_register_update_callback' )->justReturn( true );
		Lafka_Store_Api::register();

		$missing = array_values(
			array_diff(
				array(
					'woocommerce_checkout_process -> validate_datetime_fields',
					'woocommerce_checkout_process -> gate_checkout_when_closed',
					'woocommerce_add_to_cart_validation -> gate_add_to_cart_when_closed',
					'woocommerce_store_api_validate_add_to_cart -> gate_store_api_add_to_cart_when_closed',
					'woocommerce_store_api_cart_errors -> add_cart_errors',
					'woocommerce_store_api_checkout_update_order_from_request -> on_checkout_update_order_from_request',
				),
				Hooks::registered()
			)
		);

		$this->assertSame( array(), $missing );
	}
}
