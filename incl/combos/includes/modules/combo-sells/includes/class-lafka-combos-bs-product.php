<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product-related functions and filters.
 *
 * @class    WC_LafkaCombos_BS_Product
 * @version  6.7.6
 */
class WC_LafkaCombos_BS_Product {

	/*
	|--------------------------------------------------------------------------
	| Application layer functions.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get combo-sells IDs for a product.
	 *
	 * @param  mixed  $product
	 * @return array
	 */
	public static function get_combo_sell_ids( $product, $context = 'view' ) {

		$combo_sell_ids = array();

		if ( ! ( $product instanceof WC_Product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ( $product instanceof WC_Product ) && false === $product->is_type( 'combo' ) ) {

			$combo_sell_ids = $product->get_meta( '_wc_pb_combo_sell_ids', true );

			if ( ! empty( $combo_sell_ids ) && is_array( $combo_sell_ids ) ) {

				$combo_sell_ids = array_map( 'intval', $combo_sell_ids );

				// Clean up unsupported product types.
				foreach ( $combo_sell_ids as $combo_sell_index => $combo_sell_id ) {
					$product_type = WC_Product_Factory::get_product_type( $combo_sell_id );
					if ( ! in_array( $product_type, array( 'simple', 'subscription' ) ) ) {
						unset( $combo_sell_ids[ $combo_sell_index ] );
					}
				}
			}

			/**
			 * 'wc_pb_combo_sell_ids' filter.
			 *
			 * @param  array       $combo_sell_ids  Array of combo-sell IDs.
			 * @param  WC_Product  $product          Product containing the combo-sells.
			 */
			$combo_sell_ids = 'view' === $context ? apply_filters( 'wc_pb_combo_sell_ids', $combo_sell_ids, $product ) : $combo_sell_ids;
		}

		return $combo_sell_ids;
	}

	/**
	 * Prompt/title displayed above the combo-sells section in single-product pages.
	 *
	 * @param  mixed  $product
	 * @return string
	 */
	public static function get_combo_sells_title( $product, $context = 'view' ) {

		$title = '';

		if ( ! ( $product instanceof WC_Product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ( $product instanceof WC_Product ) && false === $product->is_type( 'combo' ) ) {

			$title = $product->get_meta( '_wc_pb_combo_sells_title', true );

			/**
			 * 'wc_pb_combo_sells_title' filter.
			 *
			 * @param  WC_Product  $product  Product containing the combo-sells.
			 */
			$title = 'view' === $context ? apply_filters( 'wc_pb_combo_sells_title', $title, $product ) : $title;
		}

		return $title;
	}

	/**
	 * Combo-sells discount.
	 *
	 * @since  6.0.0
	 *
	 * @param  mixed  $product
	 * @return string
	 */
	public static function get_combo_sells_discount( $product, $context = 'view' ) {

		$discount = '';

		if ( 'filters' !== WC_LafkaCombos_Product_Prices::get_combined_cart_item_discount_method() ) {
			return $discount;
		}

		if ( ! ( $product instanceof WC_Product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ( $product instanceof WC_Product ) && false === $product->is_type( 'combo' ) ) {

			$discount = WC_LafkaCombos_Helpers::cache_get( 'combo_sells_discount_' . $product->get_id() );

			if ( null === $discount ) {
				$discount = $product->get_meta( '_wc_pb_combo_sells_discount', true, 'edit' );
				WC_LafkaCombos_Helpers::cache_get( 'combo_sells_discount_' . $product->get_id(), $discount );
			}

			/**
			 * 'wc_pb_combo_sells_discount' filter.
			 *
			 * @param  WC_Product  $product  Product containing the combo-sells.
			 */
			$discount = 'view' === $context ? apply_filters( 'wc_pb_combo_sells_discount', $discount, $product ) : $discount;
		}

		return $discount;
	}

	/**
	 * Arguments used to create new combined item data objects from combo-sell IDs.
	 *
	 * @param  int         $combo_sell_id  The combo-sell ID.
	 * @param  WC_Product  $product         The parent product.
	 * @return array
	 */
	public static function get_combo_sell_data_item_args( $combo_sell_id, $product ) {

		$discount = self::get_combo_sells_discount( $product );

		/**
		 * 'wc_pb_combo_sell_data_item_args' filter.
		 *
		 * @param  int         $combo_sell_id  Combo-sell ID.
		 * @param  WC_Product  $product         Product containing the combo-sell.
		 */
		return apply_filters( 'wc_pb_combo_sell_data_item_args', array(
			'combo_id'  => $product->get_id(),
			'product_id' => $combo_sell_id,
			'meta_data'  => array(
				'quantity_min'         => 1,
				'quantity_max'         => 1,
				'priced_individually'  => 'yes',
				'shipped_individually' => 'yes',
				'optional'             => 'yes',
				'discount'             => $discount ? $discount : null,
				'stock_status'         => null,
				'disable_addons'       => 'yes'
			)
		), $combo_sell_id, $product );
	}

	/**
	 * Creates a "runtime" combo object from a list of combo-sell IDs.
	 *
	 * @param  array       $combo_sell_ids  Array of combo-sell IDs.
	 * @param  WC_Product  $product          Product containing the combo-sells.
	 * @return WC_Product_Combo
	 */
	public static function get_combo( $combo_sell_ids, $product ) {

		$combo_sell_ids    = array_map( 'intval', $combo_sell_ids );
		$combo             = new WC_Product_Combo( $product );
		$combined_data_items = array();

		foreach ( $combo_sell_ids as $combo_sell_id ) {

			$args = self::get_combo_sell_data_item_args( $combo_sell_id, $product );

			$combined_data_items[] = $args;
		}

		$combo->set_combined_data_items( $combined_data_items );

		return apply_filters( 'wc_pb_combo_sells_dummy_combo', $combo );
	}
}
