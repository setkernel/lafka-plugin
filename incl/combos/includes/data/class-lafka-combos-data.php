<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Combos Data class.
 *
 * Product Combos Data filters and includes.
 *
 * @class    WC_LafkaCombos_Data
 * @version  5.5.0
 */
class WC_LafkaCombos_Data {

	public static function init() {

		// DB API for custom PB tables.
		require_once WC_LafkaCombos_ABSPATH . 'includes/data/class-lafka-combos-db.php';

		// Combined Item Data CRUD class.
		require_once WC_LafkaCombos_ABSPATH . 'includes/data/class-wc-combined-item-data.php';

		// Product Combo CPT data store.
		require_once WC_LafkaCombos_ABSPATH . 'includes/data/class-wc-product-combo-data-store-cpt.php';

		// Register the Product Combo Custom Post Type data store.
		add_filter( 'woocommerce_data_stores', array( __CLASS__, 'register_combo_type_data_store' ), 10 );
	}

	/**
	 * Registers the Product Combo Custom Post Type data store.
	 *
	 * @param  array  $stores
	 * @return array
	 */
	public static function register_combo_type_data_store( $stores ) {

		$stores['product-combo'] = 'WC_Product_Combo_Data_Store_CPT';

		return $stores;
	}
}

WC_LafkaCombos_Data::init();
