<?php
/**
 * Glocal-scope Combo-Sell functions
 */
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Cart functions.
|--------------------------------------------------------------------------
*/

/**
 * True if a cart item is a combo-sell.
 * Instead of relying solely on cart item data, the function also checks that the alleged parent item actually exists.
 *
 * @since  5.8.0
 *
 * @param  array  $cart_item
 * @param  array  $cart_contents
 * @return boolean
 */
function wc_pb_is_combo_sell_cart_item( $cart_item, $cart_contents = false ) {

	$is_combo_sell = false;

	if ( wc_pb_get_combo_sell_cart_item_container( $cart_item, $cart_contents ) ) {
		$is_combo_sell = true;
	}

	return $is_combo_sell;
}

/**
 * Given a combo-sell cart item, find and return its parent cart item.
 * Returns the cart key of its parent cart item when the $return_id arg is true.
 *
 * @since  5.8.0
 *
 * @param  array    $cart_item
 * @param  array    $cart_contents
 * @param  boolean  $return_id
 * @return mixed
 */
function wc_pb_get_combo_sell_cart_item_container( $cart_item, $cart_contents = false, $return_id = false ) {

	if ( ! $cart_contents ) {
		$cart_contents = WC()->cart->cart_contents;
	}

	$container = false;

	if ( isset( $cart_item['combo_sell_of'] ) ) {

		$combined_sell_of = $cart_item['combo_sell_of'];

		if ( isset( $cart_contents[ $combined_sell_of ] ) ) {
			$container = $return_id ? $combined_sell_of : $cart_contents[ $combined_sell_of ];
		}
	}

	return $container;
}

/**
 * Given a combo-sells parent cart item, find and return its child cart items -- or their cart ids when the $return_ids arg is true.
 *
 * @since  5.8.0
 *
 * @param  array    $cart_item
 * @param  array    $cart_contents
 * @param  boolean  $return_ids
 * @return mixed
 */
function wc_pb_get_combo_sell_cart_items( $cart_item, $cart_contents = false, $return_ids = false ) {

	if ( ! $cart_contents ) {
		$cart_contents = WC()->cart->cart_contents;
	}

	$combo_sell_cart_items = array();

	if ( isset( $cart_item['combo_sells'] ) ) {

		$maybe_combo_sell_cart_items = $cart_item['combo_sells'];

		if ( ! empty( $maybe_combo_sell_cart_items ) && is_array( $maybe_combo_sell_cart_items ) ) {
			foreach ( $maybe_combo_sell_cart_items as $maybe_combo_sell_cart_item_key ) {
				if ( isset( $cart_contents[ $maybe_combo_sell_cart_item_key ] ) ) {
					$combo_sell_cart_items[ $maybe_combo_sell_cart_item_key ] = $cart_contents[ $maybe_combo_sell_cart_item_key ];
				}
			}
		}
	}

	return $return_ids ? array_keys( $combo_sell_cart_items ) : $combo_sell_cart_items;
}
