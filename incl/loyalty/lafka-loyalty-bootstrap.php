<?php
/**
 * Loyalty loader: the ledger, earning, checkout redemption and My Account
 * once WooCommerce is loaded and the module is on (Lafka → Modules →
 * Loyalty points, default off).
 *
 * @package Lafka\Plugin\Loyalty
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_loyalty_boot' ) ) {
	/**
	 * Load and hook the module.
	 *
	 * @return void
	 */
	function lafka_loyalty_boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once __DIR__ . '/class-lafka-loyalty-ledger.php';
		require_once __DIR__ . '/class-lafka-loyalty.php';
		require_once __DIR__ . '/class-lafka-loyalty-redeem.php';
		require_once __DIR__ . '/class-lafka-loyalty-account.php';
		require_once __DIR__ . '/class-lafka-loyalty-cli-command.php';
		if ( ! Lafka_Loyalty::enabled() ) {
			return;
		}
		Lafka_Loyalty::init();
		Lafka_Loyalty_Redeem::init();
		Lafka_Loyalty_Account::init();
	}
}
if ( did_action( 'woocommerce_loaded' ) ) {
	lafka_loyalty_boot();
} else {
	add_action( 'woocommerce_loaded', 'lafka_loyalty_boot' );
}
