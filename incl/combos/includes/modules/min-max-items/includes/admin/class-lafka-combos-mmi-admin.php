<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functions and filters.
 *
 * @class    WC_LafkaCombos_MMI_Admin
 * @version  6.6.0
 */
class WC_LafkaCombos_MMI_Admin {

	/**
	 * Setup hooks.
	 */
	public static function init() {

		// Display min/max qty settings in "Combined Products" tab.
		add_action( 'woocommerce_combined_products_admin_config', array( __CLASS__, 'display_options' ), 16 );

		// Save min/max qty settings.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_meta' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Filter hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Admin min/max settings.
	 */
	public static function display_options() {

		global $product_combo_object;

		woocommerce_wp_text_input(
			array(
				'id'            => '_wcpb_min_qty_limit',
				'value'         => $product_combo_object->get_min_combo_size( 'edit' ),
				'wrapper_class' => 'combined_product_data_field',
				'type'          => 'number',
				'label'         => __( 'Min Combo Items', 'lafka-plugin' ),
				'desc_tip'      => true,
				'description'   => __( 'Minimum total number of items in the combo.', 'lafka-plugin' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_wcpb_max_qty_limit',
				'value'         => $product_combo_object->get_max_combo_size( 'edit' ),
				'wrapper_class' => 'combined_product_data_field',
				'type'          => 'number',
				'label'         => __( 'Max Combo Items', 'lafka-plugin' ),
				'desc_tip'      => true,
				'description'   => __( 'Maximum total number of items in the combo.', 'lafka-plugin' ),
			)
		);
	}

	/**
	 * Save meta.
	 *
	 * @param  WC_Product  $product
	 * @return void
	 */
	public static function save_meta( $product ) {

		if ( $product->is_type( 'combo' ) ) {

			$props = array(
				'min_combo_size' => '',
				'max_combo_size' => '',
			);

			if ( ! empty( $_POST['_wcpb_min_qty_limit'] ) && is_numeric( $_POST['_wcpb_min_qty_limit'] ) ) {
				$props['min_combo_size'] = stripslashes( wc_clean( $_POST['_wcpb_min_qty_limit'] ) );
			}

			if ( ! empty( $_POST['_wcpb_max_qty_limit'] ) && is_numeric( $_POST['_wcpb_max_qty_limit'] ) ) {
				$props['max_combo_size'] = stripslashes( wc_clean( $_POST['_wcpb_max_qty_limit'] ) );
			}

			if ( ! $props['min_combo_size'] && ! $props['max_combo_size'] ) {
				$props = array(
					'min_combo_size' => '',
					'max_combo_size' => '',
				);
			}

			$product->set( $props );
		}
	}
}

WC_LafkaCombos_MMI_Admin::init();
