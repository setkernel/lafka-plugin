<?php
/**
 * Order tracking loader: the status stepper, its live endpoint and the
 * reorder entry points, once WooCommerce is loaded and the module is on
 * (Lafka → Modules → Order tracking, default on).
 *
 * @package Lafka\Plugin\OrderTracking
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_order_tracking_boot' ) ) {
	/**
	 * Load and hook the module.
	 *
	 * @return void
	 */
	function lafka_order_tracking_boot(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! Lafka_Options::is_enabled( 'order_tracking' ) ) {
			return;
		}
		require_once __DIR__ . '/class-lafka-order-tracking.php';
		require_once __DIR__ . '/class-lafka-order-tracking-rest.php';
		require_once __DIR__ . '/class-lafka-order-reorder.php';
		Lafka_Order_Tracking::init();
		Lafka_Order_Tracking_Rest::init();
		Lafka_Order_Reorder::init();
	}
}
if ( did_action( 'woocommerce_loaded' ) ) {
	lafka_order_tracking_boot();
} else {
	add_action( 'woocommerce_loaded', 'lafka_order_tracking_boot' );
}
