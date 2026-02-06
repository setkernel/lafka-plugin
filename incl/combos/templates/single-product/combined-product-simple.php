<?php
/**
 * Simple Combined Product template
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/combined-product-simple.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 6.3.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><div class="cart" data-title="<?php echo esc_attr( $combined_item->get_title() ); ?>" data-product_title="<?php echo esc_attr( $combined_item->get_product()->get_title() ); ?>" data-visible="<?php echo $combined_item->is_visible() ? 'yes' : 'no'; ?>" data-optional_suffix="<?php echo esc_attr( $combined_item->get_optional_suffix() ); ?>" data-optional="<?php echo $combined_item->is_optional() ? 'yes' : 'no'; ?>" data-type="<?php echo $combined_item->get_product()->get_type(); ?>" data-combined_item_id="<?php echo $combined_item->get_id(); ?>" data-custom_data="<?php echo esc_attr( json_encode( $custom_product_data ) ); ?>" data-product_id="<?php echo $combined_item->get_product()->get_id(); ?>" data-combo_id="<?php echo $combo->get_id(); ?>">
	<div class="combined_item_wrap">
		<div class="combined_item_cart_content" <?php echo $combined_item->is_optional() && ! $combined_item->is_optional_checked() ? 'style="display:none"' : ''; ?>>
			<div class="combined_item_cart_details"><?php

				if ( ! $combined_item->is_optional() ) {
					wc_get_template( 'single-product/combined-item-price.php', array(
						'combined_item' => $combined_item
					), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
				}

				// Availability html.
				echo $combined_item->get_availability_html();

				/**
				 * 'woocommerce_combined_product_add_to_cart' hook.
				 *
				 * Used to output content normally hooked to 'woocommerce_before_add_to_cart_button'.
				 *
				 * @param mixed           $combined_product_id
				 * @param WC_Combined_Item $combined_item
				 */
				do_action( 'woocommerce_combined_product_add_to_cart', $combined_item->get_product()->get_id(), $combined_item );

			?></div>
			<div class="combined_item_after_cart_details combined_item_button"><?php

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

			?></div>
		</div>
	</div>
</div>
