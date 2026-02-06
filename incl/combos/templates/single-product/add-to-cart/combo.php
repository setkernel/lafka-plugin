<?php
/**
 * Product Combo single-product template
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/add-to-cart/combo.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 5.5.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WC Core action. */
do_action( 'woocommerce_before_add_to_cart_form' ); ?>

<form method="post" enctype="multipart/form-data" class="cart cart_group combo_form <?php echo esc_attr( $classes ); ?>"><?php

	/**
	 * 'woocommerce_before_combined_items' action.
	 *
	 * @param WC_Product_Combo $product
	 */
	do_action( 'woocommerce_before_combined_items', $product );

	foreach ( $combined_items as $combined_item ) {

		/**
		 * 'woocommerce_combined_item_details' action.
		 *
		 * @hooked wc_pc_template_combined_item_details_wrapper_open  -   0
		 * @hooked wc_pc_template_combined_item_thumbnail             -   5
		 * @hooked wc_pc_template_combined_item_details_open          -  10
		 * @hooked wc_pc_template_combined_item_title                 -  15
		 * @hooked wc_pc_template_combined_item_description           -  20
		 * @hooked wc_pc_template_combined_item_product_details       -  25
		 * @hooked wc_pc_template_combined_item_details_close         -  30
		 * @hooked wc_pc_template_combined_item_details_wrapper_close - 100
		 */
		do_action( 'woocommerce_combined_item_details', $combined_item, $product );
	}

	/**
	 * 'woocommerce_after_combined_items' action.
	 *
	 * @param  WC_Product_Combo  $product
	 */
	do_action( 'woocommerce_after_combined_items', $product );

	/**
	 * 'woocommerce_combos_add_to_cart_wrap' action.
	 *
	 * @since  5.5.0
	 *
	 * @param  WC_Product_Combo  $product
	 */
	do_action( 'woocommerce_combos_add_to_cart_wrap', $product );

?></form><?php
	/** WC Core action. */
	do_action( 'woocommerce_after_add_to_cart_form' );
?>
