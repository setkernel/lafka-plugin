<?php
/**
 * Customer text messages loader: the opt-in at checkout, the sender, the
 * WhatsApp link and the settings, once WooCommerce is loaded and the module is
 * on (WooCommerce → Settings → Restaurant → Text messages, default off).
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_notify_boot' ) ) {
	/**
	 * Load and hook the module. The settings load in the admin even while the
	 * module is off, so the operator can turn it on.
	 *
	 * @return void
	 */
	function lafka_notify_boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once __DIR__ . '/class-lafka-notify-adapter.php';
		require_once __DIR__ . '/class-lafka-notify-twilio.php';
		require_once __DIR__ . '/class-lafka-notify-whatsapp.php';
		require_once __DIR__ . '/class-lafka-notify.php';
		require_once __DIR__ . '/class-lafka-notify-checkout.php';
		require_once __DIR__ . '/class-lafka-notify-links.php';
		require_once __DIR__ . '/class-lafka-notify-settings.php';
		if ( is_admin() ) {
			Lafka_Notify_Settings::init();
		}
		if ( ! Lafka_Notify::enabled() ) {
			return;
		}
		Lafka_Notify::init();
		Lafka_Notify_Checkout::init();
		Lafka_Notify_Links::init();
	}
}
if ( did_action( 'woocommerce_loaded' ) ) {
	lafka_notify_boot();
} else {
	add_action( 'woocommerce_loaded', 'lafka_notify_boot' );
}
