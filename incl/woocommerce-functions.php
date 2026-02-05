<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'wp', 'lafka_hide_single_product_price_when_eligible' );
if ( ! function_exists( 'lafka_hide_single_product_price_when_eligible' ) ) {
	/**
	 * Remove single price from single product
	 * when product is eligible for variation listings in catalogs
	 */
	function lafka_hide_single_product_price_when_eligible() {

		global $product;

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( $product && function_exists( 'lafka_is_product_eligible_for_variation_in_listings' ) && lafka_is_product_eligible_for_variation_in_listings( $product ) ) {
			/** @var WC_Product_Variable $variable_product */
			$variable_product = wc_get_product( $product );
			// Only if it has default variation
			if ( $variable_product->get_default_attributes() ) {
				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			}
		}
	}
}

// Manage variation visibility in catalog views
add_action( 'woocommerce_variation_options', 'lafka_show_variable_in_catalog_option', 10, 3 );
if ( ! function_exists( 'lafka_show_variable_in_catalog_option' ) ) {
	function lafka_show_variable_in_catalog_option( $loop, $variation_data, $variation ) {
		?>
        <label class="tips" data-tip="<?php esc_html_e( 'Enable this option to show the variation in catalog views. <br> NOTE: This will have following effects on the default WooCommerce representation of products, in order to have more fast food look:<br>- Product price will be hidden in catalogs<br>- "From - To" price in product view will be hidden if there is default variation<br>- Variation weight entries won\'t be shown in the "Additional Information" tab on Product view' , 'lafka-plugin' ); ?>">
			<?php esc_html_e( 'Show in Catalog?', 'lafka-plugin' ); ?>
            <input type="checkbox" class="checkbox lafka_variable_in_catalog"
                   name="_lafka_variable_in_catalog[<?php echo esc_attr( $loop ); ?>]" <?php checked( $variation->_lafka_variable_in_catalog, true ); ?> />
        </label>
		<?php
	}
}

add_action( 'woocommerce_save_product_variation', 'lafka_save_variable_in_catalog_option', 10, 2 );
if ( ! function_exists( 'lafka_save_variable_in_catalog_option' ) ) {
	function lafka_save_variable_in_catalog_option( $variation_id, $i ) {
		update_post_meta( $variation_id, '_lafka_variable_in_catalog', isset( $_POST['_lafka_variable_in_catalog'][ $i ] ) );
	}
}

add_action( 'woocommerce_variable_product_bulk_edit_actions', 'lafka_list_bulk_update_variable_in_catalog_option' );
if ( ! function_exists( 'lafka_list_bulk_update_variable_in_catalog_option' ) ) {
	function lafka_list_bulk_update_variable_in_catalog_option() {
		?>
        <optgroup label="<?php esc_attr_e( 'Lafka variations in catalog', 'lafka-plugin' ); ?>">
            <option value="lafka_variable_in_catalog_show"><?php esc_html_e( 'Show all', 'lafka-plugin' ); ?></option>
            <option value="lafka_variable_in_catalog_hide"><?php esc_html_e( 'Hide all', 'lafka-plugin' ); ?></option>
        </optgroup>
		<?php
	}
}

add_action( 'woocommerce_bulk_edit_variations_default', 'lafka_save_bulk_update_variable_in_catalog_option', 10, 4 );
if ( ! function_exists( 'lafka_save_bulk_update_variable_in_catalog_option' ) ) {
	function lafka_save_bulk_update_variable_in_catalog_option( $bulk_action, $data, $product_id, $variations ) {
		foreach ( $variations as $variation_id ) {
			if ( $bulk_action === 'lafka_variable_in_catalog_show' ) {
				update_post_meta( $variation_id, '_lafka_variable_in_catalog', true );
			} elseif ( $bulk_action === 'lafka_variable_in_catalog_hide' ) {
				update_post_meta( $variation_id, '_lafka_variable_in_catalog', false );
			}
		}
	}
}