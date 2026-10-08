<?php
/**
 * Variation swatches helper functions.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main instance of plugin
 *
 * @return Lafka_WC_Variation_Swatches
 */
function lafka_wcvs() {
	return Lafka_WC_Variation_Swatches::instance();
}

/**
 * Construct plugin when plugins loaded in order to make sure WooCommerce API is fully loaded
 * Check if WooCommerce is not activated then show an admin notice
 * or create the main instance of plugin
 */
function lafka_wc_variation_swatches_constructor() {
	if ( function_exists( 'WC' ) ) {
		lafka_wcvs();
	}
}

add_action( 'plugins_loaded', 'lafka_wc_variation_swatches_constructor' );
