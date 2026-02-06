<?php
/**
 * Variable Combined Product template
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/combined-product-variable.php'.
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

?><div class="cart combined_item_cart_content" data-title="<?php echo esc_attr( $combined_item->get_title() ); ?>" data-product_title="<?php echo esc_attr( $combined_item->get_product()->get_title() ); ?>" data-visible="<?php echo $combined_item->is_visible() ? 'yes' : 'no'; ?>" data-optional_suffix="<?php echo esc_attr( $combined_item->get_optional_suffix() ); ?>" data-optional="<?php echo $combined_item->is_optional() ? 'yes' : 'no'; ?>" data-type="<?php echo $combined_product->get_type(); ?>" data-product_variations="<?php echo htmlspecialchars( json_encode( $combined_product_variations ) ); ?>" data-combined_item_id="<?php echo $combined_item->get_id(); ?>" data-custom_data="<?php echo esc_attr( json_encode( $custom_product_data ) ); ?>" data-product_id="<?php echo $combined_product_id; ?>" data-combo_id="<?php echo $combo_id; ?>" <?php echo $combined_item->is_optional() && ! $combined_item->is_optional_checked() ? 'style="display:none"' : ''; ?>>
	<table class="variations" cellspacing="0">
		<tbody>
		<?php

		foreach ( $combined_product_attributes as $attribute_name => $options ) {

			$is_attribute_value_configurable = $combined_item->display_product_variation_attribute_dropdown( $attribute_name );

			?>
				<tr class="attribute_options <?php echo $is_attribute_value_configurable ? 'attribute_value_configurable' : 'attribute_value_static'; ?>" data-attribute_label="<?php echo esc_attr( wc_attribute_label( $attribute_name ) ); ?>">
					<td class="label">
						<label for="<?php echo esc_attr( sanitize_title( $attribute_name ) ) . '_' . $combined_item->get_id(); ?>">
						<?php

						echo wc_attribute_label( $attribute_name );

						if ( $is_attribute_value_configurable ) {
							?>
								<abbr class="required" title="<?php _e( 'Required option', 'lafka-plugin' ); ?>">*</abbr>
								<?php
						}

						?>
						</label>
					</td>
					<td class="value">
					<?php

					echo wc_pc_template_combined_variation_attribute_options(
						array(
							'options'       => $options,
							'attribute'     => $attribute_name,
							'combined_item' => $combined_item,
						)
					);

					?>
									</td>
				</tr>
				<?php
		}

		?>
		</tbody>
	</table>
	<?php

	/**
	 * 'woocommerce_combined_product_add_to_cart' hook.
	 *
	 * Used to output content normally hooked to 'woocommerce_before_add_to_cart_button'.
	 *
	 * @param  int              $combined_product_id
	 * @param  WC_Combined_Item  $combined_item
	 */
	do_action( 'woocommerce_combined_product_add_to_cart', $combined_product_id, $combined_item );

	?>
	<div class="single_variation_wrap combined_item_wrap">
	<?php

		/**
		 * 'woocommerce_combined_single_variation' hook.
		 *
		 * Used to output variation data.
		 *
		 * @since  4.12.0
		 *
		 * @param  int              $combined_product_id
		 * @param  WC_Combined_Item  $combined_item
		 *
		 * @hooked wc_combos_single_variation          - 10
		 * @hooked wc_combos_single_variation_template - 20
		 */
		do_action( 'woocommerce_combined_single_variation', $combined_product_id, $combined_item );

	?>
	</div>
</div>
