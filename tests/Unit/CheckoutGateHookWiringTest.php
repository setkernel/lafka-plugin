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
 * The bootstrap's no-op add_action()/add_filter() shims hide registrations from
 * Brain Monkey, so this reads the registration calls from source.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CheckoutGateHookWiringTest extends TestCase {

	/**
	 * source file => [ hook, callback method ] registrations that must exist.
	 */
	private const GATES = array(
		'incl/timeslots/class-lafka-timeslots.php' => array(
			array( 'woocommerce_checkout_process', 'validate_datetime_fields' ),
		),
		'incl/order-hours/Lafka_Order_Hours.php'   => array(
			array( 'woocommerce_checkout_process', 'gate_checkout_when_closed' ),
			array( 'woocommerce_add_to_cart_validation', 'gate_add_to_cart_when_closed' ),
			array( 'woocommerce_store_api_validate_add_to_cart', 'gate_store_api_add_to_cart_when_closed' ),
		),
		'incl/store-api/class-lafka-store-api.php' => array(
			array( 'woocommerce_store_api_cart_errors', 'add_cart_errors' ),
			array( 'woocommerce_store_api_checkout_update_order_from_request', 'on_checkout_update_order_from_request' ),
		),
	);

	public function test_each_gate_is_registered_on_its_blocking_hook(): void {
		$missing = array();
		foreach ( self::GATES as $file => $registrations ) {
			$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
			foreach ( $registrations as list( $hook, $method ) ) {
				$pattern = sprintf(
					"/add_(?:action|filter)\(\s*'%s'\s*,\s*array\(\s*(?:\\\$this|__CLASS__)\s*,\s*'%s'\s*\)/",
					preg_quote( $hook, '/' ),
					preg_quote( $method, '/' )
				);
				if ( ! preg_match( $pattern, $src ) ) {
					$missing[] = "{$file}: {$hook} -> {$method}";
				}
			}
		}

		$this->assertSame( array(), $missing );
	}
}
