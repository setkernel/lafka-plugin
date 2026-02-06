<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One Page Checkout Compatibility.
 *
 * @version  6.4.0
 */
class WC_LafkaCombos_OPC_Compatibility {

	public static function init() {

		// OPC support.
		add_action( 'wcopc_combo_add_to_cart', array( __CLASS__, 'opc_single_add_to_cart_combo' ) );
		add_filter( 'wcopc_allow_cart_item_modification', array( __CLASS__, 'opc_disallow_combined_cart_item_modification' ), 10, 4 );
	}

	/**
	 * OPC Single-product combo-type add-to-cart template.
	 *
	 * @param  int  $opc_post_id
	 * @return void
	 */
	public static function opc_single_add_to_cart_combo( $opc_post_id ) {

		global $product;

		// Enqueue script
		wp_enqueue_script( 'wc-add-to-cart-combo' );
		wp_enqueue_style( 'wc-combo-css' );

		if ( $product->is_purchasable() ) {

			$combined_items = $product->get_combined_items();
			$form_classes  = array( 'layout_' . $product->get_layout(), 'group_mode_' . $product->get_group_mode() );

			if ( ! empty( $combined_items ) ) {

				ob_start();

				wc_get_template( 'single-product/add-to-cart/combo.php', array(
					'combined_items'     => $combined_items,
					'product'           => $product,
					'classes'           => implode( ' ', apply_filters( 'woocommerce_combo_form_classes', $form_classes, $product ) ),
					// Back-compat.
					'product_id'        => $product->get_id(),
					'availability_html' => wc_get_stock_html( $product ),
					'combo_price_data' => $product->get_combo_form_data()
				), false, WC_LafkaCombos()->plugin_path() . '/templates/' );

				echo str_replace( array( '<form method="post" enctype="multipart/form-data"', '</form>' ), array( '<div', '</div>' ), ob_get_clean() );
			}
		}
	}

	/**
	 * Prevent OPC from managing combined items.
	 *
	 * @param  bool    $allow
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @param  string  $opc_id
	 * @return bool
	 */
	public static function opc_disallow_combined_cart_item_modification( $allow, $cart_item, $cart_item_key, $opc_id ) {
		if ( wc_pc_is_combined_cart_item( $cart_item ) ) {
			$allow = false;
		}
		return $allow;
	}
}

WC_LafkaCombos_OPC_Compatibility::init();
