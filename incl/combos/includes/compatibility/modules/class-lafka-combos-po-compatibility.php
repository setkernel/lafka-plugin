<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre Orders Compatibility.
 *
 * @since  4.11.4
 */
class WC_LafkaCombos_PO_Compatibility {

	public static function init() {

		// Pre-orders support.
		add_filter( 'wc_pre_orders_cart_item_meta', array( __CLASS__, 'remove_combined_pre_orders_cart_item_meta' ), 10, 2 );
		add_filter( 'wc_pre_orders_order_item_meta', array( __CLASS__, 'remove_combined_pre_orders_order_item_meta' ), 10, 3 );
	}

	/**
	 * Remove combined cart item meta "Available On" text.
	 *
	 * @param  array  $pre_order_meta
	 * @param  array  $cart_item_data
	 * @return array
	 */
	public static function remove_combined_pre_orders_cart_item_meta( $pre_order_meta, $cart_item_data ) {
		if ( wc_pc_is_combined_cart_item( $cart_item_data ) ) {
			$pre_order_meta = array();
		}
		return $pre_order_meta;
	}

	/**
	 * Remove combined order item meta "Available On" text.
	 *
	 * @param  array     $pre_order_meta
	 * @param  array     $order_item
	 * @param  WC_Order  $order
	 * @return array
	 */
	public static function remove_combined_pre_orders_order_item_meta( $pre_order_meta, $order_item, $order ) {
		if ( wc_pc_maybe_is_combined_order_item( $order_item, $order ) ) {
			$pre_order_meta = array();
		}
		return $pre_order_meta;
	}
}

WC_LafkaCombos_PO_Compatibility::init();
