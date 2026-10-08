<?php
/**
 * Shipping areas bootstrap function.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Function for delaying initialization of the extension until after WooCommerce is loaded.
 */
function lafka_shipping_areas_initialize() {

	// This is also a great place to check for the existence of the WooCommerce class
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$GLOBALS['lafka_shipping_areas'] = Lafka_Shipping_Areas::instance();
}

add_action( 'plugins_loaded', 'lafka_shipping_areas_initialize', 10 );
