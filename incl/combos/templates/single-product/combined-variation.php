<?php
/**
 * Combined Variation Product template
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/combined-variation.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 5.6.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="woocommerce-variation-add-to-cart variations_button combined_item_after_cart_details combined_item_button">
	<input type="hidden" class="variation_id" name="<?php echo $combo_fields_prefix . 'combo_variation_id_' . $combined_item->get_id(); ?>" value=""/><?php

	/**
	 * 'woocommerce_after_combined_item_cart_details' hook.
	 *
	 * @since 5.0.0
	 *
	 * @param WC_Combined_Item $combined_item
	 *
	 * @hooked wc_pc_template_default_combined_item_qty - 10
	 */
	do_action( 'woocommerce_after_combined_item_cart_details', $combined_item );

?></div><?php
