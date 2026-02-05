<?php
/**
 * Product Combos global functions
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Products.
|--------------------------------------------------------------------------
*/

/**
 * Create a WC_Combined_Item instance.
 *
 * @since  5.0.0
 *
 * @param  mixed  $item
 * @param  mixed  $parent
 * @return mixed
 */
function wc_pc_get_combined_item( $item, $parent = false ) {

	$data = null;

	if ( is_numeric( $item ) ) {
		$data = WC_LafkaCombos_DB::get_combined_item( absint( $item ) );
	} elseif ( $item instanceof WC_Combined_Item_Data ) {
		$data = $item;
	}

	if ( ! is_null( $data ) ) {
		$combined_item = new WC_Combined_Item( $data, $parent );

		if ( $combined_item->exists() ) {
			return $combined_item;
		}
	}

	return false;
}

/**
 * Get a map of the combined item DB IDs and product combo post IDs associated with a (combined) product.
 *
 * @since  5.0.0
 *
 * @param  mixed    $product
 * @param  boolean  $allow_cache
 * @return array
 */
function wc_pc_get_combined_product_map( $product, $allow_cache = true ) {

	if ( is_object( $product ) ) {
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	} else {
		$product_id = absint( $product );
	}

	$use_cache = $allow_cache && ! defined( 'WC_LafkaCombos_DEBUG_TRANSIENTS' ) && ! defined( 'WC_LafkaCombos_UPDATING' );

	$transient_name             = 'wc_combined_product_data';
	$transient_version          = WC_Cache_Helper::get_transient_version( 'product' );
	$combined_product_data_array = get_transient( $transient_name );
	$combined_product_data       = false;

	if ( $use_cache && is_array( $combined_product_data_array ) && isset( $combined_product_data_array[ $product_id ] ) && is_array( $combined_product_data_array[ $product_id ] ) && isset( $combined_product_data_array[ $product_id ][ 'combo_ids' ] ) && is_array( $combined_product_data_array[ $product_id ][ 'combo_ids' ] ) ) {
		if ( isset( $combined_product_data_array[ $product_id ][ 'version' ] ) && $transient_version === $combined_product_data_array[ $product_id ][ 'version' ] ) {
			$combined_product_data = $combined_product_data_array[ $product_id ][ 'combo_ids' ];
		}
	}

	if ( false === $combined_product_data ) {

		$args = array(
			'product_id' => $product_id,
			'return'     => 'id=>combo_id'
		);

		$combined_product_data = WC_LafkaCombos_DB::query_combined_items( $args );

		if ( is_array( $combined_product_data_array ) ) {

			$combined_product_data_array[ $product_id ] = array(
				'combo_ids' => $combined_product_data,
				'version'    => $transient_version
			);

		} else {

			$combined_product_data_array = array(
				$product_id => array(
					'combo_ids' => $combined_product_data,
					'version'    => $transient_version
				)
			);
		}

		if ( ! defined( 'WC_LafkaCombos_UPDATING' ) ) {

			// Delete expired entries.
			if ( ! empty( $combined_product_data_array ) ) {
				foreach ( $combined_product_data_array as $product_id_key => $data ) {
					if ( ! isset( $data[ 'version' ] ) || $transient_version !== $data[ 'version' ] ) {
						unset( $combined_product_data_array[ $product_id_key ] );
					}
				}
			}

			delete_transient( $transient_name );
			set_transient( $transient_name, $combined_product_data_array, DAY_IN_SECONDS * 30 );
		}
	}

	return $combined_product_data;
}

/*
|--------------------------------------------------------------------------
| Cart.
|--------------------------------------------------------------------------
*/

/**
 * Given a combined cart item, find and return its container cart item - the Combo - or its cart id when the $return_id arg is true.
 *
 * @since  5.0.0
 *
 * @param  array    $combined_cart_item
 * @param  array    $cart_contents
 * @param  boolean  $return_id
 * @return mixed
 */
function wc_pc_get_combined_cart_item_container( $combined_cart_item, $cart_contents = false, $return_id = false ) {

	if ( ! $cart_contents ) {
		$cart_contents = isset( WC()->cart ) ? WC()->cart->cart_contents : array();
	}

	$container = false;

	if ( wc_pc_maybe_is_combined_cart_item( $combined_cart_item ) ) {

		$combined_by = $combined_cart_item[ 'combined_by' ];

		if ( isset( $cart_contents[ $combined_by ] ) ) {
			$container = $return_id ? $combined_by : $cart_contents[ $combined_by ];
		}
	}

	return $container;
}

/**
 * Given a combo container cart item, find and return its child cart items - or their cart ids when the $return_ids arg is true.
 *
 * @since  5.0.0
 *
 * @param  array    $container_cart_item
 * @param  array    $cart_contents
 * @param  boolean  $return_ids
 * @return mixed
 */
function wc_pc_get_combined_cart_items( $container_cart_item, $cart_contents = false, $return_ids = false ) {

	if ( ! $cart_contents ) {
		$cart_contents = isset( WC()->cart ) ? WC()->cart->cart_contents : array();
	}

	$combined_cart_items = array();

	if ( wc_pc_is_combo_container_cart_item( $container_cart_item ) ) {

		$combined_items = $container_cart_item[ 'combined_items' ];

		if ( ! empty( $combined_items ) && is_array( $combined_items ) ) {
			foreach ( $combined_items as $combined_cart_item_key ) {
				if ( isset( $cart_contents[ $combined_cart_item_key ] ) ) {
					$combined_cart_items[ $combined_cart_item_key ] = $cart_contents[ $combined_cart_item_key ];
				}
			}
		}
	}

	return $return_ids ? array_keys( $combined_cart_items ) : $combined_cart_items;
}

/**
 * True if a cart item is part of a combo.
 * Instead of relying solely on cart item data, the function also checks that the alleged parent item actually exists.
 *
 * @since  5.0.0
 *
 * @param  array  $cart_item
 * @param  array  $cart_contents
 * @return boolean
 */
function wc_pc_is_combined_cart_item( $cart_item, $cart_contents = false ) {

	$is_combined = false;

	if ( wc_pc_get_combined_cart_item_container( $cart_item, $cart_contents ) ) {
		$is_combined = true;
	}

	return $is_combined;
}

/**
 * True if a cart item appears to be part of a combo.
 * The result is purely based on cart item data - the function does not check that a valid parent item actually exists.
 *
 * @since  5.0.0
 *
 * @param  array  $cart_item
 * @return boolean
 */
function wc_pc_maybe_is_combined_cart_item( $cart_item ) {

	$is_combined = false;

	if ( ! empty( $cart_item[ 'combined_by' ] ) && ! empty( $cart_item[ 'combined_item_id' ] ) && ! empty( $cart_item[ 'stamp' ] ) ) {
		$is_combined = true;
	}

	return $is_combined;
}

/**
 * True if a cart item appears to be a combo container item.
 *
 * @since  5.0.0
 *
 * @param  array  $cart_item
 * @return boolean
 */
function wc_pc_is_combo_container_cart_item( $cart_item ) {

	$is_combo = false;

	if ( isset( $cart_item[ 'combined_items' ] ) && ! empty( $cart_item[ 'stamp' ] ) ) {
		$is_combo = true;
	}

	return $is_combo;
}

/*
|--------------------------------------------------------------------------
| Orders.
|--------------------------------------------------------------------------
*/

/**
 * Given a combined order item, find and return its container order item - the Combo - or its order item id when the $return_id arg is true.
 *
 * @since  5.0.0
 *
 * @param  array     $combined_order_item
 * @param  WC_Order  $order
 * @param  boolean   $return_id
 * @return mixed
 */
function wc_pc_get_combined_order_item_container( $combined_order_item, $order = false, $return_id = false ) {

	$result = false;

	if ( wc_pc_maybe_is_combined_order_item( $combined_order_item ) ) {

		$container = WC_LafkaCombos_Helpers::cache_get( 'order_item_container_' . $combined_order_item->get_id() );

		if ( null === $container ) {

			if ( false === $order ) {
				if ( is_callable( array( $combined_order_item, 'get_order' ) ) ) {

					$order_id = $combined_order_item->get_order_id();
					$order    = WC_LafkaCombos_Helpers::cache_get( 'order_' . $order_id );

					if ( null === $order ) {
						$order = $combined_order_item->get_order();
						WC_LafkaCombos_Helpers::cache_set( 'order_' . $order_id, $order );
					}

				} else {
					$msg = 'get_order() is not callable on the supplied $order_item. No $order object given.';
					_doing_it_wrong( __FUNCTION__ . '()', $msg, '5.3.0' );
				}
			}

			$order_items = is_object( $order ) ? $order->get_items( 'line_item' ) : $order;

			if ( ! empty( $order_items ) ) {
				foreach ( $order_items as $order_item_id => $order_item ) {

					$is_container = false;

					if ( isset( $order_item[ 'combo_cart_key' ] ) ) {
						$is_container = $combined_order_item[ 'combined_by' ] === $order_item[ 'combo_cart_key' ];
					} else {
						$is_container = isset( $order_item[ 'stamp' ] ) && $order_item[ 'stamp' ] === $combined_order_item[ 'stamp' ] && ! isset( $order_item[ 'combined_by' ] );
					}

					if ( $is_container ) {
						WC_LafkaCombos_Helpers::cache_set( 'order_item_container_' . $combined_order_item->get_id(), $order_item );
						$container = $order_item;
						break;
					}
				}
			}
		}

		if ( $container && is_callable( array( $container, 'get_id' ) ) ) {
			$result = $return_id ? $container->get_id() : $container;
		}
	}

	return $result;
}

/**
 * Given a combo container order item, find and return its child order items - or their order item ids when the $return_ids arg is true.
 *
 * @since  5.0.0
 *
 * @param  array     $container_order_item
 * @param  WC_Order  $order
 * @param  boolean   $return_ids
 * @return mixed
 */
function wc_pc_get_combined_order_items( $container_order_item, $order = false, $return_ids = false ) {

	$combined_order_items = array();

	if ( wc_pc_is_combo_container_order_item( $container_order_item ) ) {

		$combined_cart_keys = maybe_unserialize( $container_order_item[ 'combined_items' ] );

		if ( ! empty( $combined_cart_keys ) && is_array( $combined_cart_keys ) ) {

			if ( false === $order ) {
				if ( is_callable( array( $container_order_item, 'get_order' ) ) ) {

					$order_id = $container_order_item->get_order_id();
					$order    = WC_LafkaCombos_Helpers::cache_get( 'order_' . $order_id );

					if ( null === $order ) {
						$order = $container_order_item->get_order();
						WC_LafkaCombos_Helpers::cache_set( 'order_' . $order_id, $order );
					}

				} else {
					$msg = 'get_order() is not callable on the supplied $order_item. No $order object given.';
					_doing_it_wrong( __FUNCTION__ . '()', $msg, '5.3.0' );
				}
			}

			$order_items = is_object( $order ) ? $order->get_items( 'line_item' ) : $order;

			if ( ! empty( $order_items ) ) {
				foreach ( $order_items as $order_item_id => $order_item ) {

					$is_child = false;

					if ( isset( $order_item[ 'combo_cart_key' ] ) ) {
						$is_child = in_array( $order_item[ 'combo_cart_key' ], $combined_cart_keys ) ? true : false;
					} else {
						$is_child = isset( $order_item[ 'stamp' ] ) && $order_item[ 'stamp' ] == $container_order_item[ 'stamp' ] && isset( $order_item[ 'combined_by' ] ) ? true : false;
					}

					if ( $is_child ) {
						$combined_order_items[ $order_item_id ] = $order_item;
					}
				}
			}
		}
	}

	return $return_ids ? array_keys( $combined_order_items ) : $combined_order_items;
}

/**
 * True if an order item is part of a combo.
 * Instead of relying solely on the existence of item meta, the function also checks that the alleged parent item actually exists.
 *
 * @since  5.0.0
 *
 * @param  array     $order_item
 * @param  WC_Order  $order
 * @return boolean
 */
function wc_pc_is_combined_order_item( $order_item, $order = false ) {

	$is_combined = false;

	if ( wc_pc_get_combined_order_item_container( $order_item, $order ) ) {
		$is_combined = true;
	}

	return $is_combined;
}

/**
 * True if an order item appears to be part of a combo.
 * The result is purely based on item meta - the function does not check that a valid parent item actually exists.
 *
 * @since  5.0.0
 *
 * @param  array  $order_item
 * @return boolean
 */
function wc_pc_maybe_is_combined_order_item( $order_item ) {

	$is_combined = false;

	if ( ! empty( $order_item[ 'combined_by' ] ) ) {
		$is_combined = true;
	}

	return $is_combined;
}

/**
 * True if an order item appears to be a combo container item.
 *
 * @since  5.0.0
 *
 * @param  array  $order_item
 * @return boolean
 */
function wc_pc_is_combo_container_order_item( $order_item ) {

	$is_combo = false;

	if ( isset( $order_item[ 'combined_items' ] ) ) {
		$is_combo = true;
	}

	return $is_combo;
}

/*
|--------------------------------------------------------------------------
| Formatting.
|--------------------------------------------------------------------------
*/

/**
 * Get precision depending on context.
 *
 * @return string
 */
function wc_pc_price_num_decimals( $context = '' ) {

	$wc_price_num_decimals_cache_key = 'wc_price_num_decimals' . ( 'extended' === $context ? '_ext' : '' );
	$wc_price_num_decimals           = WC_LafkaCombos_Helpers::cache_get( $wc_price_num_decimals_cache_key );

	if ( null === $wc_price_num_decimals ) {

		if ( 'extended' === $context ) {
			$wc_price_num_decimals = wc_get_rounding_precision();
		} else {
			$wc_price_num_decimals = wc_get_price_decimals();
		}

		WC_LafkaCombos_Helpers::cache_set( $wc_price_num_decimals_cache_key, $wc_price_num_decimals );
	}

	return $wc_price_num_decimals;
}

/*
|--------------------------------------------------------------------------
| Conditionals.
|--------------------------------------------------------------------------
*/

/**
 * True if the current single product page is of a combo-type product.
 *
 * @since  5.7.0
 *
 * @return boolean
 */
function wc_pc_is_product_combo() {
	global $product;
	return function_exists( 'is_product' ) && is_product() && ! empty( $product ) && is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'combo' );
}
