<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end AJAX filters for 'get_variation'.
 *
 * @class    WC_LafkaCombos_Ajax
 * @version  5.0.0
 * @since    5.0.0
 */
class WC_LafkaCombos_Ajax {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Filter core 'get_variation' AJAX requests in order to account for combined item variation filters and discounts.
		add_action( 'wc_ajax_get_variation', array( __CLASS__, 'ajax_get_combined_variation' ), 0 );
	}

	/**
	 * Filters core 'get_variation' AJAX requests in order to account for combined item variation filters and discounts.
	 */
	public static function ajax_get_combined_variation() {

		if ( ! empty( $_POST['custom_data'] ) ) {
			$combo_id         = isset( $_POST['custom_data']['combo_id'] ) ? absint( $_POST['custom_data']['combo_id'] ) : false;
			$combined_item_id = isset( $_POST['custom_data']['combined_item_id'] ) ? absint( $_POST['custom_data']['combined_item_id'] ) : false;

			// Unset custom data to prevent issues in 'WC_Product_Variable::get_matching_variation'.
			unset( $_POST['custom_data'] );

			if ( $combo_id && $combined_item_id && false !== ( $combined_item = wc_pc_get_combined_item( $combined_item_id, $combo_id ) ) ) {
				add_filter( 'woocommerce_available_variation', array( $combined_item, 'filter_variation' ), 10, 3 );
				$combined_item->add_price_filters();
			}
		}
	}
}

WC_LafkaCombos_Ajax::init();
