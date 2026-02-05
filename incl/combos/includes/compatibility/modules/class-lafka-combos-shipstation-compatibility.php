<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shipstation Integration.
 *
 * @version  5.5.0
 */
class WC_LafkaCombos_Shipstation_Compatibility {

	public static function init() {
		// Shipstation compatibility.
		add_action( 'woocommerce_api_wc_shipstation', array( __CLASS__, 'add_filters' ), 5 );
	}

	/**
	 * Modify the returned order items and products to return the correct items/weights/values for shipping.
	 */
	public static function add_filters() {
		add_filter( 'woocommerce_order_get_items', array( WC_LafkaCombos()->order, 'get_order_items' ), 10, 2 );
		add_filter( 'woocommerce_order_item_product', array( WC_LafkaCombos()->order, 'get_product_from_item' ), 10, 2 );
	}
}

WC_LafkaCombos_Shipstation_Compatibility::init();
