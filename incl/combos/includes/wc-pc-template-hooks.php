<?php
/**
 * Product Combos template hooks
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Single product template for Product Combos. Form location: Default.
add_action( 'woocommerce_combo_add_to_cart', 'wc_pc_template_add_to_cart' );

// Single product template for Product Combos. Form location: After summary.
add_action( 'woocommerce_after_single_product_summary', 'wc_pc_template_add_to_cart_after_summary', -1000 );

// Single product add-to-cart buttons area template for Product Combos.
add_action( 'woocommerce_combos_add_to_cart_wrap', 'wc_pc_template_add_to_cart_wrap' );

// Single product add-to-cart button template for Product Combos.
add_action( 'woocommerce_combos_add_to_cart_button', 'wc_pc_template_add_to_cart_button' );

// Combined item wrapper open.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_details_wrapper_open', 0, 2 );

// Combined item image.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_thumbnail', 5, 2 );

// Combined item details container open.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_details_open', 10, 2 );

// Combined item title.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_title', 15, 2 );

// Combined item description.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_description', 20, 2 );

// Combined product details template.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_product_details', 25, 2 );

// Combined item details container close.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_details_close', 30, 2 );

// Combined item qty template in tabular layout.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_tabular_combined_item_qty', 35, 2 );

// Combined item wrapper close.
add_action( 'woocommerce_combined_item_details', 'wc_pc_template_combined_item_details_wrapper_close', 100, 2 );

// Combined item qty.
add_action( 'woocommerce_after_combined_item_cart_details', 'wc_pc_template_default_combined_item_qty' );

// Combined variation template.
add_action( 'woocommerce_combined_single_variation', 'wc_pc_template_single_variation', 10, 2 );
add_action( 'woocommerce_combined_single_variation', 'wc_pc_template_single_variation_template', 20, 2 );

// Open and close table.
add_action( 'woocommerce_before_combined_items', 'wc_pc_template_before_combined_items', 100 );
add_action( 'woocommerce_after_combined_items', 'wc_pc_template_after_combined_items', 0 );

// Combined item attributes.
add_action( 'woocommerce_product_additional_information', 'wc_pc_template_combined_item_attributes', 11 );
