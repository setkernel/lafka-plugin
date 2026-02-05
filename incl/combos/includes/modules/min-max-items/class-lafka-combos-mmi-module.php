<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combo-Sells Module
 *
 * @version  6.4.0
 */
class WC_LafkaCombos_MMI_Module extends WCS_PC_Abstract_Module {

	/**
	 * Core.
	 */
	public function load_core() {

		// Admin.
		if ( is_admin() ) {
			require_once( WC_LafkaCombos_ABSPATH . 'includes/modules/min-max-items/includes/admin/class-lafka-combos-mmi-admin.php' );
		}

		// Product-related functions and hooks.
		require_once( WC_LafkaCombos_ABSPATH . 'includes/modules/min-max-items/includes/class-lafka-combos-mmi-product.php' );
	}

	/**
	 * Cart.
	 */
	public function load_cart() {
		// Cart-related functions and hooks.
		require_once( WC_LafkaCombos_ABSPATH . 'includes/modules/min-max-items/includes/class-lafka-combos-mmi-cart.php' );
	}

	/**
	 * Display.
	 */
	public function load_display() {
		// Display-related functions and hooks.
		require_once( WC_LafkaCombos_ABSPATH . 'includes/modules/min-max-items/includes/class-lafka-combos-mmi-display.php' );
	}
}
