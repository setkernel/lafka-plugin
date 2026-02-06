<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce core Product Exporter support.
 *
 * @class    WC_LafkaCombos_Product_Export
 * @version  6.6.0
 */
class WC_LafkaCombos_Product_Export {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Add CSV columns for exporting combo data.
		add_filter( 'woocommerce_product_export_column_names', array( __CLASS__, 'add_columns' ) );
		add_filter( 'woocommerce_product_export_product_default_columns', array( __CLASS__, 'add_columns' ) );

		// "Combined Items" column data.
		add_filter( 'woocommerce_product_export_product_column_wc_pb_combined_items', array( __CLASS__, 'export_combined_items' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_min_combo_size', array( __CLASS__, 'export_min_combo_size' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_max_combo_size', array( __CLASS__, 'export_max_combo_size' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_layout', array( __CLASS__, 'export_layout' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_group_mode', array( __CLASS__, 'export_group_mode' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_editable_in_cart', array( __CLASS__, 'export_editable_in_cart' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_sold_individually_context', array( __CLASS__, 'export_sold_individually_context' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_add_to_cart_form_location', array( __CLASS__, 'export_add_to_cart_form_location' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_combo_sells', array( __CLASS__, 'export_combo_sells' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_combo_sells_title', array( __CLASS__, 'export_combo_sells_title' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_wc_pb_combo_sells_discount', array( __CLASS__, 'export_combo_sells_discount' ), 10, 2 );
	}

	/**
	 * Add CSV columns for exporting combo data.
	 *
	 * @param  array  $columns
	 * @return array  $columns
	 */
	public static function add_columns( $columns ) {

		$columns[ 'wc_pb_combined_items' ]             = __( 'Combined Items (JSON-encoded)', 'lafka-plugin' );
		$columns[ 'wc_pb_min_combo_size' ]           = __( 'Min Combo Size', 'lafka-plugin' );
		$columns[ 'wc_pb_max_combo_size' ]           = __( 'Max Combo Size', 'lafka-plugin' );
		$columns[ 'wc_pb_layout' ]                    = __( 'Combo Layout', 'lafka-plugin' );
		$columns[ 'wc_pb_group_mode' ]                = __( 'Combo Group Mode', 'lafka-plugin' );
		$columns[ 'wc_pb_editable_in_cart' ]          = __( 'Combo Cart Editing', 'lafka-plugin' );
		$columns[ 'wc_pb_sold_individually_context' ] = __( 'Combo Sold Individually', 'lafka-plugin' );
		$columns[ 'wc_pb_add_to_cart_form_location' ] = __( 'Combo Form Location', 'lafka-plugin' );
		$columns[ 'wc_pb_combo_sells' ]              = __( 'Combo Sells', 'lafka-plugin' );
		$columns[ 'wc_pb_combo_sells_title' ]        = __( 'Combo Sells Title', 'lafka-plugin' );
		$columns[ 'wc_pb_combo_sells_discount' ]     = __( 'Combo Sells Discount', 'lafka-plugin' );

		return $columns;
	}

	/**
	 * Combo data column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_combined_items( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {

			$combined_items = $product->get_combined_data_items( 'edit' );

			if ( ! empty( $combined_items ) ) {

				$data = array();

				foreach ( $combined_items as $combined_item ) {

					$combined_item_id    = $combined_item->get_id();
					$combined_item_data  = $combined_item->get_data();

					// Combined item stock information not needed.
					unset( $combined_item_data[ 'meta_data' ][ 'stock_status' ] );
					unset( $combined_item_data[ 'meta_data' ][ 'max_stock' ] );

					$combined_product_id = $combined_item->get_product_id();
					$combined_product    = wc_get_product( $combined_product_id );

					if ( ! $combined_product ) {
						return $value;
					}

					// Not needed as we will be re-creating all combined items during import.
					unset( $combined_item_data[ 'combined_item_id' ] );
					unset( $combined_item_data[ 'combo_id' ] );

					$combined_product_sku = $combined_product->get_sku( 'edit' );

					// Refer to exported products by their SKU, if present.
					$combined_item_data[ 'product_id' ] = $combined_product_sku ? $combined_product_sku : 'id:' . $combined_product_id;

					$data[ $combined_item_id ] = $combined_item_data;
				}

				$value = json_encode( $data );
			}
		}

		return $value;
	}

	/**
	 * "Min Combo Size" column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_min_combo_size( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_min_combo_size( 'edit' );
		}

		return $value;
	}

	/**
	 * "Max Combo Size" column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_max_combo_size( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_max_combo_size( 'edit' );
		}

		return $value;
	}

	/**
	 * "Combo Layout" column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_layout( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_layout( 'edit' );
		}

		return $value;
	}

	/**
	 * "Combo Group Mode" column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_group_mode( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_group_mode( 'edit' );
		}

		return $value;
	}

	/**
	 * "Combo Cart Editing" column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_editable_in_cart( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_editable_in_cart( 'edit' ) ? 1 : 0;
		}

		return $value;
	}

	/**
	 * "Combo Sold Individually" column content.
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_sold_individually_context( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_sold_individually_context( 'edit' );
		}

		return $value;
	}

	/**
	 * "Combo Form Location" column content.
	 *
	 * @since  5.8.1
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_add_to_cart_form_location( $value, $product ) {

		if ( $product->is_type( 'combo' ) ) {
			$value = $product->get_add_to_cart_form_location( 'edit' );
		}

		return $value;
	}

	/**
	 * "Combo Sells" field content.
	 *
	 * @since  6.1.0
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_combo_sells( $value, $product ) {

		if ( ! $product->is_type( 'combo' ) ) {

			$combo_sells = $product->get_meta( '_wc_pb_combo_sell_ids', true );

			if ( ! empty( $combo_sells ) ) {

				$product_list = array();

				foreach ( $combo_sells as $combo_sell ) {

					if ( $linked_product = wc_get_product( $combo_sell ) ) {

						if ( $linked_product->get_sku() ) {
							$product_list[] = str_replace( ',', '\\,', $linked_product->get_sku() );
						} else {
							$product_list[] = 'id:' . $linked_product->get_id();
						}
					}
				}

				$value = implode( ', ', $product_list );
			}
		}

		return $value;
	}

	/**
	 * "Combo Sells Title" field content.
	 *
	 * @since  6.1.0
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_combo_sells_title( $value, $product ) {

		if ( ! $product->is_type( 'combo' ) ) {

			$combo_sells_title = $product->get_meta( '_wc_pb_combo_sells_title', true );

			if ( ! empty( $combo_sells_title ) ) {
				$value = $combo_sells_title;
			}
		}

		return $value;
	}

	/**
	 * "Combo Sells Discount" field content.
	 *
	 * @since  6.1.0
	 *
	 * @param  mixed       $value
	 * @param  WC_Product  $product
	 * @return mixed       $value
	 */
	public static function export_combo_sells_discount( $value, $product ) {

		if ( ! $product->is_type( 'combo' ) ) {

			$combo_sells_discount = $product->get_meta( '_wc_pb_combo_sells_discount', true );

			if ( ! empty( $combo_sells_discount ) ) {
				$value = $combo_sells_discount;
			}
		}

		return $value;
	}
}

WC_LafkaCombos_Product_Export::init();
