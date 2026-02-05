<?php
/**
 * Product Combo add-to-cart buttons wrapper template
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/add-to-cart/combo-add-to-cart.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 6.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="cart combo_data combo_data_<?php echo $product_id; ?>" data-combo_form_data="<?php echo esc_attr( json_encode( $combo_form_data ) ); ?>" data-combo_id="<?php echo $product_id; ?>"><?php

	if ( $is_purchasable ) {

		/** WC Core action. */
		do_action( 'woocommerce_before_add_to_cart_button' );

		?><div class="combo_wrap">
			<div class="combo_price"></div>
			<?php
				/**
				 * 'woocommerce_combos_after_combo_price' action.
				 *
				 * @since 6.7.6
				 */
				do_action( 'woocommerce_after_combo_price' );
			?>
			<div class="combo_error" style="display:none">
				<div class="woocommerce-info">
					<ul class="msg"></ul>
				</div>
			</div>
			<?php
				/**
				 * 'woocommerce_combos_after_combo_price' action.
				 *
				 * @since 6.7.6
				 */
				do_action( 'woocommerce_before_combo_availability' );
			?>
			<div class="combo_availability">
			<?php
				// Availability html.
				echo $availability_html;
			?>
			</div>
			<?php
				/**
				 * 'woocommerce_combos_after_combo_price' action.
				 *
				 * @since 6.7.6
				 */
				do_action( 'woocommerce_before_combo_add_to_cart_button' );
			?>
			<div class="combo_button"><?php

				/**
				 * woocommerce_combos_add_to_cart_button hook.
				 *
				 * @hooked wc_pc_template_add_to_cart_button - 10
				 */
				do_action( 'woocommerce_combos_add_to_cart_button', $product );

			?></div>
			<input type="hidden" name="add-to-cart" value="<?php echo $product_id; ?>" />
		</div><?php

		/** WC Core action. */
		do_action( 'woocommerce_after_add_to_cart_button' );

	} else {

		?><div class="combo_unavailable woocommerce-info"><?php
			echo $purchasable_notice;
		?></div><?php
	}

?></div>
