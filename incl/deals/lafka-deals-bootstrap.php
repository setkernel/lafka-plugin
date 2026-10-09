<?php
/**
 * Deals module loader: the product type, its admin panel, the builder and
 * the cart rules, once WooCommerce is loaded and the module is on
 * (Lafka → Modules → Deals, default on).
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lafka-deals-category.php';
require_once __DIR__ . '/class-lafka-deals.php';

if ( ! function_exists( 'lafka_deals_boot' ) ) {
	/**
	 * Load and hook the module.
	 *
	 * @return void
	 */
	function lafka_deals_boot(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! Lafka_Deals::enabled() ) {
			return;
		}
		require_once __DIR__ . '/class-wc-product-lafka-deal.php';
		require_once __DIR__ . '/class-lafka-deals-cart.php';
		require_once __DIR__ . '/class-lafka-deals-builder.php';
		require_once __DIR__ . '/class-lafka-deals-conditions.php';
		require_once __DIR__ . '/class-lafka-deals-nudge.php';
		Lafka_Deals::init();
		Lafka_Deals_Cart::init();
		Lafka_Deals_Conditions::init();
		Lafka_Deals_Builder::init();
		Lafka_Deals_Nudge::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/class-lafka-deals-admin.php';
			Lafka_Deals_Admin::init();
		}
	}
}
if ( did_action( 'woocommerce_loaded' ) ) {
	lafka_deals_boot();
} else {
	add_action( 'woocommerce_loaded', 'lafka_deals_boot' );
}
