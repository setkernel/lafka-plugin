<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add hooks to the edit posts view for the 'product' post type.
 *
 * @class    WC_LafkaCombos_Admin_Post_Types
 * @version  6.5.0
 */
class WC_LafkaCombos_Admin_Post_Types {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Add details to admin product stock info when the combined stock is insufficient.
		add_filter( 'woocommerce_admin_stock_html', array( __CLASS__, 'admin_stock_html' ), 10, 2 );

		// Add support for bulk editing Combo's Regular/Sale price.
		add_filter( 'woocommerce_bulk_edit_save_price_product_types', array( __CLASS__, 'bulk_edit_price' ), 10, 1 );
	}

	/**
	 * Add details to admin stock info when contents stock is insufficient.
	 *
	 * @param  string      $stock_status
	 * @param  WC_Product  $product
	 * @return string
	 */
	public static function admin_stock_html( $stock_status, $product ) {

		if ( 'combo' === $product->get_type() ) {
			if ( $product->is_parent_in_stock() && ( $product->contains( 'out_of_stock_strict' ) || 'outofstock' === $product->get_combined_items_stock_status() ) ) {

				ob_start();

				?><mark class="outofstock insufficient_stock"><?php _e( 'Insufficient stock', 'lafka-plugin' ); ?></mark><?php

				if ( $product->contains( 'out_of_stock_strict' ) ) {

					?><div class="row-actions">
						<span class="view"><a href="<?php echo admin_url( 'admin.php?page=wc-reports&tab=stock&report=insufficient_stock&combo_id=' . $product->get_id() ) ?>" rel="bookmark" aria-label="<?php _e( 'View Report', 'lafka-plugin' ); ?>"><?php _e( 'View Report', 'lafka-plugin' ); ?></a></span>
					</div><?php
				}

				$stock_status = ob_get_clean();
			}
		}

		return $stock_status;
	}

	/**
	 * Add support for bulk editing Combo's Regular/Sale price.
	 *
	 * @param  array      $supported_product_types
	 * @return array
	 */
	public static function bulk_edit_price( $supported_product_types ) {

		$supported_product_types[] = 'combo';

		return $supported_product_types;
	}
}

WC_LafkaCombos_Admin_Post_Types::init();
