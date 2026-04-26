<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo-Sells Module
 *
 * @version  5.8.0
 */
class WC_LafkaCombos_BS_Module extends WCS_PC_Abstract_Module {

	/**
	 * Core.
	 */
	public function load_core() {

		// Admin.
		if ( is_admin() ) {
			require_once WC_LafkaCombos_ABSPATH . 'includes/modules/combo-sells/includes/admin/class-lafka-combos-bs-admin.php';
		}

		// Global-scope functions.
		require_once WC_LafkaCombos_ABSPATH . 'includes/modules/combo-sells/includes/wc-pb-bs-functions.php';

		// Product-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/modules/combo-sells/includes/class-lafka-combos-bs-product.php';
	}

	/**
	 * Cart.
	 */
	public function load_cart() {
		// Cart-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/modules/combo-sells/includes/class-lafka-combos-bs-cart.php';
	}

	/**
	 * Order.
	 */
	public function load_order() {
		// Order-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/modules/combo-sells/includes/class-lafka-combos-bs-order.php';
	}

	/**
	 * Display.
	 */
	public function load_display() {
		// Display-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/modules/combo-sells/includes/class-lafka-combos-bs-display.php';
	}
}
