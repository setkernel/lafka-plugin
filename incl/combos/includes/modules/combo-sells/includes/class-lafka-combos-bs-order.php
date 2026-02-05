<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-related functions and filters.
 *
 * @class    WC_LafkaCombos_BS_Order
 * @version  5.8.0
 */
class WC_LafkaCombos_BS_Order {

	/**
	 * Setup hooks.
	 */
	public static function init() {

		// Add combo-sell meta to order items.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_combo_sell_order_item_meta' ), 10, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| Filter hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add combo-sell meta to order items.
	 *
	 * @param  WC_Order_Item  $order_item
	 * @param  string         $cart_item_key
	 * @param  array          $cart_item
	 * @return void
	 */
	public static function add_combo_sell_order_item_meta( $order_item, $cart_item_key, $cart_item ) {

		if ( $bunde_sell_cart_items = wc_pb_get_combo_sell_cart_items( $cart_item, false, true ) ) {
			$order_item->add_meta_data( '_combo_sells', $bunde_sell_cart_items, true );
			$order_item->add_meta_data( '_combo_sell_key', $cart_item_key, true );
		} elseif ( wc_pb_is_combo_sell_cart_item( $cart_item ) ) {
			$order_item->add_meta_data( '_combo_sell_of', $cart_item[ 'combo_sell_of' ], true );
			$order_item->add_meta_data( '_combo_sell_key', $cart_item_key, true );
		}
	}
}

WC_LafkaCombos_BS_Order::init();
