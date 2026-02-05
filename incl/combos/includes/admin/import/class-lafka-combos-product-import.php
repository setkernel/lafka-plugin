<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce core Product Importer support.
 *
 * @class    WC_LafkaCombos_Product_Import
 * @version  6.6.0
 */
class WC_LafkaCombos_Product_Import {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Map custom column titles.
		add_filter( 'woocommerce_csv_product_import_mapping_options', array( __CLASS__, 'map_columns' ) );
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns', array( __CLASS__, 'add_columns_to_mapping_screen' ) );

		// Parse combined items.
		add_filter( 'woocommerce_product_importer_parsed_data', array( __CLASS__, 'parse_combined_items' ), 10, 2 );

		// Parse Combo Sells IDs.
		add_filter( 'woocommerce_product_importer_parsed_data', array( __CLASS__, 'parse_combo_sells' ), 10, 2 );

		// Set combo-type props.
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( __CLASS__, 'set_combo_props' ), 10, 2 );
	}

	/**
	 * Register the 'Custom Column' column in the importer.
	 *
	 * @param  array  $options
	 * @return array  $options
	 */
	public static function map_columns( $options ) {

		$options[ 'wc_pb_combined_items' ]             = __( 'Combined Items (JSON-encoded)', 'lafka-plugin' );
		$options[ 'wc_pb_min_combo_size' ]           = __( 'Min Combo Size', 'lafka-plugin' );
		$options[ 'wc_pb_max_combo_size' ]           = __( 'Max Combo Size', 'lafka-plugin' );
		$options[ 'wc_pb_layout' ]                    = __( 'Combo Layout', 'lafka-plugin' );
		$options[ 'wc_pb_group_mode' ]                = __( 'Combo Group Mode', 'lafka-plugin' );
		$options[ 'wc_pb_editable_in_cart' ]          = __( 'Combo Cart Editing', 'lafka-plugin' );
		$options[ 'wc_pb_sold_individually_context' ] = __( 'Combo Sold Individually', 'lafka-plugin' );
		$options[ 'wc_pb_add_to_cart_form_location' ] = __( 'Combo Form Location', 'lafka-plugin' );
		$options[ 'wc_pb_combo_sells' ]              = __( 'Combo Sells', 'lafka-plugin' );
		$options[ 'wc_pb_combo_sells_title' ]        = __( 'Combo Sells Title', 'lafka-plugin' );
		$options[ 'wc_pb_combo_sells_discount' ]     = __( 'Combo Sells Discount', 'lafka-plugin' );

		return $options;
	}

	/**
	 * Add automatic mapping support for custom columns.
	 *
	 * @param  array  $columns
	 * @return array  $columns
	 */
	public static function add_columns_to_mapping_screen( $columns ) {

		$columns[ __( 'Combined Items (JSON-encoded)', 'lafka-plugin' ) ] = 'wc_pb_combined_items';
		$columns[ __( 'Min Combo Size', 'lafka-plugin' ) ]              = 'wc_pb_min_combo_size';
		$columns[ __( 'Max Combo Size', 'lafka-plugin' ) ]              = 'wc_pb_max_combo_size';
		$columns[ __( 'Combo Layout', 'lafka-plugin' ) ]                = 'wc_pb_layout';
		$columns[ __( 'Combo Group Mode', 'lafka-plugin' ) ]            = 'wc_pb_group_mode';
		$columns[ __( 'Combo Cart Editing', 'lafka-plugin' ) ]          = 'wc_pb_editable_in_cart';
		$columns[ __( 'Combo Sold Individually', 'lafka-plugin' ) ]     = 'wc_pb_sold_individually_context';
		$columns[ __( 'Combo Form Location', 'lafka-plugin' ) ]         = 'wc_pb_add_to_cart_form_location';
		$columns[ __( 'Combo Sells', 'lafka-plugin' ) ]                 = 'wc_pb_combo_sells';
		$columns[ __( 'Combo Sells Title', 'lafka-plugin' ) ]           = 'wc_pb_combo_sells_title';
		$columns[ __( 'Combo Sells Discount', 'lafka-plugin' ) ]        = 'wc_pb_combo_sells_discount';

		// Always add English mappings.
		$columns[ 'Combined Items (JSON-encoded)' ] = 'wc_pb_combined_items';
		$columns[ 'Min Combo Size' ]              = 'wc_pb_min_combo_size';
		$columns[ 'Max Combo Size' ]              = 'wc_pb_max_combo_size';
		$columns[ 'Combo Layout' ]                = 'wc_pb_layout';
		$columns[ 'Combo Group Mode' ]            = 'wc_pb_group_mode';
		$columns[ 'Combo Cart Editing' ]          = 'wc_pb_editable_in_cart';
		$columns[ 'Combo Sold Individually' ]     = 'wc_pb_sold_individually_context';
		$columns[ 'Combo Form Location' ]         = 'wc_pb_add_to_cart_form_location';
		$columns[ 'Combo Sells' ]                 = 'wc_pb_combo_sells';
		$columns[ 'Combo Sells Title' ]           = 'wc_pb_combo_sells_title';
		$columns[ 'Combo Sells Discount' ]        = 'wc_pb_combo_sells_discount';

		return $columns;
	}

	/**
	 * Decode combined data items and parse relative IDs.
	 *
	 * @param  array                    $parsed_data
	 * @param  WC_Product_CSV_Importer  $importer
	 * @return array
	 */
	public static function parse_combined_items( $parsed_data, $importer ) {

		if ( ! empty( $parsed_data[ 'wc_pb_combined_items' ] ) ) {

			$combined_data_items = json_decode( $parsed_data[ 'wc_pb_combined_items' ], true );

			unset( $parsed_data[ 'wc_pb_combined_items' ] );

			if ( is_array( $combined_data_items ) ) {

				$parsed_data[ 'wc_pb_combined_items' ] = array();

				foreach ( $combined_data_items as $combined_data_item_key => $combined_data_item ) {

					$combined_product_id = $combined_data_items[ $combined_data_item_key ][ 'product_id' ];

					$parsed_data[ 'wc_pb_combined_items' ][ $combined_data_item_key ]                 = $combined_data_item;
					$parsed_data[ 'wc_pb_combined_items' ][ $combined_data_item_key ][ 'product_id' ] = $importer->parse_relative_field( $combined_product_id );
				}
			}
		}

		return $parsed_data;
	}

	/**
	 * Decode Combo Sells and parse relative IDs.
	 *
	 * @since  6.1.0
	 *
	 * @param  array                    $parsed_data
	 * @param  WC_Product_CSV_Importer  $importer
	 * @return array
	 */
	public static function parse_combo_sells( $parsed_data, $importer ) {

		if ( ! empty( $parsed_data[ 'wc_pb_combo_sells' ] ) ) {

			$parsed_data[ 'meta_data' ][] = array(
				'key'   => '_wc_pb_combo_sell_ids',
				'value' => $importer->parse_relative_comma_field( $parsed_data[ 'wc_pb_combo_sells' ] )
			);
		}

		if ( ! empty( $parsed_data[ 'wc_pb_combo_sells_title' ] ) ) {

			$parsed_data[ 'meta_data' ][] = array(
				'key'   => '_wc_pb_combo_sells_title',
				'value' => wp_kses_post( $parsed_data[ 'wc_pb_combo_sells_title' ] )
			);
		}

		if ( ! empty( $parsed_data[ 'wc_pb_combo_sells_discount' ] ) ) {

			$parsed_data[ 'meta_data' ][] = array(
				'key'   => '_wc_pb_combo_sells_discount',
				'value' => wc_format_decimal( $parsed_data[ 'wc_pb_combo_sells_discount' ] )
			);
		}

		return $parsed_data;
	}


	/**
	 * Set combo-type props.
	 *
	 * @param  array  $parsed_data
	 * @return array
	 */
	public static function set_combo_props( $product, $data ) {

		if ( ( $product instanceof WC_Product ) && $product->is_type( 'combo' ) ) {

			$props = array();

			if ( isset( $data[ 'wc_pb_combined_items' ] ) ) {
				$props[ 'combined_data_items' ] = ! empty( $data[ 'wc_pb_combined_items' ] ) ? $data[ 'wc_pb_combined_items' ] : array();
			}

			if ( isset( $data[ 'wc_pb_min_combo_size' ] ) ) {
				$props[ 'min_combo_size' ] = strval( $data[ 'wc_pb_min_combo_size' ] );
			}

			if ( isset( $data[ 'wc_pb_max_combo_size' ] ) ) {
				$props[ 'max_combo_size' ] = strval( $data[ 'wc_pb_max_combo_size' ] );
			}

			if ( isset( $data[ 'wc_pb_editable_in_cart' ] ) ) {
				$props[ 'editable_in_cart' ] = 1 === intval( $data[ 'wc_pb_editable_in_cart' ] ) ? 'yes' : 'no';
			}

			if ( isset( $data[ 'wc_pb_layout' ] ) ) {
				$props[ 'layout' ] = strval( $data[ 'wc_pb_layout' ] );
			}

			if ( isset( $data[ 'wc_pb_group_mode' ] ) ) {
				$props[ 'group_mode' ] = strval( $data[ 'wc_pb_group_mode' ] );
			}

			if ( isset( $data[ 'wc_pb_sold_individually_context' ] ) ) {
				$props[ 'sold_individually_context' ] = strval( $data[ 'wc_pb_sold_individually_context' ] );
			}

			if ( isset( $data[ 'wc_pb_add_to_cart_form_location' ] ) ) {
				$props[ 'add_to_cart_form_location' ] = strval( $data[ 'wc_pb_add_to_cart_form_location' ] );
			}

			if ( ! empty( $props ) ) {
				$product->set_props( $props );
			}
		}

		return $product;
	}
}

WC_LafkaCombos_Product_Import::init();
