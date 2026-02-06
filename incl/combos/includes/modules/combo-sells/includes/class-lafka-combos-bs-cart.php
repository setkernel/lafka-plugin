<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart-related functions and filters.
 *
 * @class    WC_LafkaCombos_BS_Cart
 * @version  6.6.4
 */
class WC_LafkaCombos_BS_Cart {

	/**
	 * Internal flag for bypassing filters.
	 *
	 * @var array
	 */
	private static $bypass_filters = array();

	/**
	 * Setup hooks.
	 */
	public static function init() {

		// Validate combo-sell add-to-cart.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 100, 6 );

		// Add combo-sells to the cart. Must run before WooCommerce sets the session data on 'woocommerce_add_to_cart' (20).
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'combo_sells_add_to_cart' ), 15, 6 );

		// Filter the add-to-cart success message.
		add_filter( 'wc_add_to_cart_message_html', array( __CLASS__, 'combo_sells_add_to_cart_message_html' ), 10, 2 );

		if ( 'filters' === WC_LafkaCombos_Product_Prices::get_combined_cart_item_discount_method() ) {
			// Allow combo-sells discounts to be applied.
			add_filter( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'load_combo_sells_into_session' ), 10 );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Application layer functions.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get the posted combo-sells configuration of a product.
	 *
	 * @param  WC_Product  $product
	 * @return array
	 */
	public static function get_posted_combo_sells_configuration( $product ) {

		if ( ! ( $product instanceof WC_Product ) ) {
			$product = wc_get_product( $product );
		}

		$combo_sells_add_to_cart_configuration = array();

		// Any combo-sell IDs present?
		$combo_sell_ids = WC_LafkaCombos_BS_Product::get_combo_sell_ids( $product );

		if ( ! empty( $combo_sell_ids ) ) {

			// Construct a dummy combo to collect the posted form content.
			$combo        = WC_LafkaCombos_BS_Product::get_combo( $combo_sell_ids, $product );
			$combined_items = $combo->get_combined_items();
			$configuration = WC_LafkaCombos()->cart->get_posted_combo_configuration( $combo );

			foreach ( $combined_items as $combined_item_id => $combined_item ) {

				if ( isset( $configuration[ $combined_item_id ] ) ) {
					$combined_item_configuration = $configuration[ $combined_item_id ];
				} else {
					continue;
				}

				if ( isset( $combined_item_configuration[ 'optional_selected' ] ) && 'no' === $combined_item_configuration[ 'optional_selected' ] ) {
					continue;
				}

				if ( isset( $combined_item_configuration[ 'quantity' ] ) && absint( $combined_item_configuration[ 'quantity' ] ) === 0 ) {
					continue;
				}

				$combo_sell_quantity = isset( $combined_item_configuration[ 'quantity' ] ) ? absint( $combined_item_configuration[ 'quantity' ] ) : $combined_item->get_quantity();

				$combo_sells_add_to_cart_configuration[ $combined_item_id ] = array(
					'product_id' => $combined_item->get_product()->get_id(),
					'quantity'   => $combo_sell_quantity
				);
			}
		}

		return $combo_sells_add_to_cart_configuration;
	}

	/*
	|--------------------------------------------------------------------------
	| Filter hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validates add-to-cart for combo-sells.
	 *
	 * @param  boolean  $add
	 * @param  int      $product_id
	 * @param  int      $quantity
	 * @param  mixed    $variation_id
	 * @param  array    $variations
	 * @param  array    $cart_item_data
	 * @return boolean
	 */
	public static function validate_add_to_cart( $add, $product_id, $quantity, $variation_id = '', $variations = array(), $cart_item_data = array() ) {

		if ( $add ) {

			$product         = wc_get_product( $product_id );
			$combo_sell_ids = WC_LafkaCombos_BS_Product::get_combo_sell_ids( $product );

			if ( ! empty( $combo_sell_ids ) ) {

				// Construct a dummy combo to validate the posted form content.
				$combo = WC_LafkaCombos_BS_Product::get_combo( $combo_sell_ids, $product );

				if ( ( $combo instanceof WC_Product_Combo ) && false === WC_LafkaCombos()->cart->validate_combo_add_to_cart( $combo, $quantity, $cart_item_data ) ) {
					$add = false;
				}
			}
		}

		return $add;
	}

	/**
	 * Adds combo-sells to the cart on the 'woocommerce_add_to_cart' action.
	 * Important: This must run before WooCommerce sets cart session data on 'woocommerce_add_to_cart' (20).
	 *
	 * @param  string  $parent_cart_item_key
	 * @param  int     $parent_id
	 * @param  int     $parent_quantity
	 * @param  int     $variation_id
	 * @param  array   $variation
	 * @param  array   $cart_item_data
	 * @return void
	 */
	public static function combo_sells_add_to_cart( $parent_cart_item_key, $parent_id, $parent_quantity, $variation_id, $variation, $cart_item_data ) {

		// Only proceed if the product was added to the cart via a form or query string.
		if ( empty( $_REQUEST[ 'add-to-cart' ] ) || absint( $_REQUEST[ 'add-to-cart' ] ) !== absint( $parent_id ) ) {
			return;
		}

		$product = $variation_id > 0 ? wc_get_product( $parent_id ) : WC()->cart->cart_contents[ $parent_cart_item_key ][ 'data' ];

		$combo_sells_configuration = self::get_posted_combo_sells_configuration( $product );

		if ( ! empty( $combo_sells_configuration ) ) {
			foreach ( $combo_sells_configuration as $combo_sell_configuration ) {
				// Add the combo-sell to the cart.
				$combo_sell_cart_item_key = WC()->cart->add_to_cart( $combo_sell_configuration[ 'product_id' ], $combo_sell_configuration[ 'quantity' ] );
			}
		}

		self::load_combo_sells_into_session( WC()->cart );
	}

	/**
	 * Filter the add-to-cart success message to include combo-sells.
	 *
	 * @param  string  $message
	 * @param  array   $products
	 * @return string
	 */
	public static function combo_sells_add_to_cart_message_html( $message, $products ) {

		if ( isset( self::$bypass_filters[ 'add_to_cart_message_html' ] ) && self::$bypass_filters[ 'add_to_cart_message_html' ] === 1 ) {
			return $message;
		}

		$parent_product_ids = array_keys( $products );
		$parent_product_id  = current( $parent_product_ids );

		$combo_sells_configuration = self::get_posted_combo_sells_configuration( $parent_product_id );

		if ( ! empty( $combo_sells_configuration ) ) {

			foreach ( $combo_sells_configuration as $combo_sell_configuration ) {
				$products[ $combo_sell_configuration[ 'product_id' ] ] = $combo_sell_configuration[ 'quantity' ];
			}

			self::$bypass_filters[ 'add_to_cart_message_html' ] = 1;
			$message = wc_add_to_cart_message( $products, true );
			self::$bypass_filters[ 'add_to_cart_message_html' ] = 0;
		}

		return $message;
	}

	/**
	 * Allow combo-sell discounts to be applied by PB.
	 *
	 * @since  6.0.0
	 *
	 * @param  array  $cart
	 * @return array
	 */
	public static function load_combo_sells_into_session( $cart ) {

		if ( empty( $cart->cart_contents ) ) {
			return;
		}

		$combo_sells_by_id       = array();
		$cart_item_parent_product = array();
		$search_cart_item_keys    = array();
		$apply_to_cart_item_keys  = array();

		// Identify items to search for combo-sells and items to apply combo sells to.
		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {

			if ( wc_pc_maybe_is_combined_cart_item( $cart_item ) || wc_pc_is_combo_container_cart_item( $cart_item ) ) {
				continue;
			}

			if ( function_exists( 'wc_cp_maybe_is_composited_cart_item' ) && function_exists( 'wc_cp_is_composite_container_cart_item' ) && ( wc_cp_maybe_is_composited_cart_item( $cart_item ) || wc_cp_is_composite_container_cart_item( $cart_item ) ) ) {
				continue;
			}

			$search_cart_item_keys[] = $cart_item_key;

			if ( ! $cart_item[ 'data' ]->is_type( array( 'simple', 'subscription' ) ) ) {
				continue;
			}

			$apply_to_cart_item_keys[] = $cart_item_key;
		}

		/**
		 * 'woocommerce_combo_sells_search_cart_items' filter.
		 *
		 * @since  6.6.0
		 *
		 * @param  array   $cart_item_keys
		 * @param  string  $parent_item
		 * @param  array   $parent_item_name
		 */
		$search_cart_item_keys = apply_filters( 'woocommerce_combo_sells_search_cart_items', $search_cart_item_keys );

		/**
		 * 'woocommerce_combo_sells_apply_to_cart_items' filter.
		 *
		 * @since  6.6.0
		 *
		 * @param  bool    $cart_item
		 * @param  string  $parent_item
		 * @param  array  $parent_item_name
		 */
		$apply_to_cart_item_keys = apply_filters( 'woocommerce_combo_sells_apply_to_cart_items', $apply_to_cart_item_keys );

		// Identify potential combo-sells, keeping associations to parents with highest discounts.
		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {

			if ( ! in_array( $cart_item_key, $search_cart_item_keys ) ) {
				continue;
			}

			$product = $cart_item[ 'data' ];

			if ( $product->is_type( 'variation' ) ) {
				$product = wc_get_product( $product->get_parent_id() );
			}

			$cart_item_combo_sells          = WC_LafkaCombos_BS_Product::get_combo_sell_ids( $product );
			$cart_item_combo_sells_discount = WC_LafkaCombos_BS_Product::get_combo_sells_discount( $product );

			if ( ! empty( $cart_item_combo_sells ) ) {

				$cart_item_parent_product[ $cart_item_key ] = $product;

				foreach ( $cart_item_combo_sells as $combo_sell_id ) {

					if ( ! isset( $combo_sells_by_id[ $combo_sell_id ] ) ) {

						$combo_sells_by_id[ $combo_sell_id ] = array(
							'parent_key' => $cart_item_key,
							'discount'   => $cart_item_combo_sells_discount
						);

					// Keep the highest discount.
					} elseif ( $cart_item_combo_sells_discount > $combo_sells_by_id[ $combo_sell_id ][ 'discount' ] ) {

						$combo_sells_by_id[ $combo_sell_id ] = array(
							'parent_key' => $cart_item_key,
							'discount'   => $cart_item_combo_sells_discount
						);
					}
				}
			}

			// Clean up keys.
			if ( isset( $cart_item[ 'combo_sells' ] ) ) {
				unset( WC()->cart->cart_contents[ $cart_item_key ][ 'combo_sells' ] );
			}
			if ( isset( $cart_item[ 'combo_sell_of' ] ) ) {
				unset( WC()->cart->cart_contents[ $cart_item_key ][ 'combo_sell_of' ] );
			}
			if ( isset( $cart_item[ 'combo_sell_discount' ] ) ) {
				unset( WC()->cart->cart_contents[ $cart_item_key ][ 'combo_sell_discount' ] );
			}
		}

		if ( empty( $combo_sells_by_id ) ) {
			return;
		}

		// Scan cart for combo-sells and apply cart item data and associations.
		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {

			if ( ! in_array( $cart_item_key, $apply_to_cart_item_keys ) ) {
				continue;
			}

			// Found a new combo-sell?
			if ( isset( $combo_sells_by_id[ $cart_item[ 'product_id' ] ] ) ) {

				$parent_cart_item_key = $combo_sells_by_id[ $cart_item[ 'product_id' ] ][ 'parent_key' ];

				WC()->cart->cart_contents[ $cart_item_key ][ 'combo_sell_of' ] = $parent_cart_item_key;

				if ( $combo_sells_by_id[ $cart_item[ 'product_id' ] ][ 'discount' ] ) {
					WC()->cart->cart_contents[ $cart_item_key ][ 'combo_sell_discount' ] = $combo_sells_by_id[ $cart_item[ 'product_id' ] ][ 'discount' ];
				}

				if ( ! isset( WC()->cart->cart_contents[ $parent_cart_item_key ][ 'combo_sells' ] ) ) {
					WC()->cart->cart_contents[ $parent_cart_item_key ][ 'combo_sells' ] = array( $cart_item_key );
				} elseif ( ! in_array( $cart_item_key, WC()->cart->cart_contents[ $parent_cart_item_key ][ 'combo_sells' ] ) ) {
					WC()->cart->cart_contents[ $parent_cart_item_key ][ 'combo_sells' ][] = $cart_item_key;
				}
			}
		}

		// Apply combo-sell discounts.
		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {

			if ( ! $parent_item_key = wc_pb_get_combo_sell_cart_item_container( $cart_item, false, true ) ) {
				continue;
			}

			if ( empty( $cart_item_parent_product[ $parent_item_key ] ) ) {
				continue;
			}

			$combo        = WC_LafkaCombos_BS_Product::get_combo( array( $cart_item[ 'product_id' ] ), $cart_item_parent_product[ $parent_item_key ] );
			$combined_items = $combo->get_combined_items();
			$combined_item  = ! empty( $combined_items ) ? current( $combined_items ) : false;

			if ( $combined_item ) {

				if ( 'filters' === WC_LafkaCombos_Product_Prices::get_combined_cart_item_discount_method() ) {
					WC_LafkaCombos_Cart::set_product_cart_prop( $cart_item[ 'data' ], 'combined_cart_item', $combined_item );
				}
			}
		}
	}
}

WC_LafkaCombos_BS_Cart::init();
