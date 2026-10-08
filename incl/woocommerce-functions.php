<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'lafka_meta_variable_in_catalog' ) ) {
	/**
	 * Canonical meta key for the per-variation "show in catalog" flag.
	 *
	 * This is the single source of truth for the persisted post-meta key that
	 * the plugin writes and the theme reads across the repo boundary. The value
	 * is a frozen DB key — it MUST stay '_lafka_variable_in_catalog' so existing
	 * stored meta keeps resolving. The accessor exists for documentation/SSOT so
	 * consumers do not duplicate the bare string; it is not a rename hook.
	 *
	 * @return string
	 */
	function lafka_meta_variable_in_catalog() {
		return '_lafka_variable_in_catalog';
	}
}

add_action( 'wp', 'lafka_hide_single_product_price_when_eligible' );
if ( ! function_exists( 'lafka_hide_single_product_price_when_eligible' ) ) {
	/**
	 * Remove single price from single product
	 * when product is eligible for variation listings in catalogs
	 */
	function lafka_hide_single_product_price_when_eligible() {

		global $product;

		$current_product = is_object( $product ) ? $product : wc_get_product( get_the_ID() );

		if ( $current_product && function_exists( 'lafka_is_product_eligible_for_variation_in_listings' ) && lafka_is_product_eligible_for_variation_in_listings( $current_product ) ) {
			/** @var WC_Product_Variable $variable_product */
			$variable_product = wc_get_product( $current_product );
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
		<label class="tips" data-tip="<?php esc_html_e( 'Enable this option to show the variation in catalog views. <br> NOTE: This will have following effects on the default WooCommerce representation of products, in order to have more fast food look:<br>- Product price will be hidden in catalogs<br>- "From - To" price in product view will be hidden if there is default variation<br>- Variation weight entries won\'t be shown in the "Additional Information" tab on Product view', 'lafka-plugin' ); ?>">
			<?php esc_html_e( 'Show in Catalog?', 'lafka-plugin' ); ?>
			<input type="checkbox" class="checkbox lafka_variable_in_catalog"
					name="_lafka_variable_in_catalog[<?php echo esc_attr( $loop ); ?>]" <?php checked( $variation->_lafka_variable_in_catalog, true ); ?> />
		</label>
		<?php // An unticked checkbox posts nothing; this marks the field as submitted. ?>
		<input type="hidden" name="_lafka_variable_in_catalog_field[<?php echo esc_attr( $loop ); ?>]" value="1" />
		<?php
	}
}

add_action( 'woocommerce_save_product_variation', 'lafka_save_variable_in_catalog_option', 10, 2 );
if ( ! function_exists( 'lafka_save_variable_in_catalog_option' ) ) {
	/**
	 * Persist the per-variation "show in catalog" checkbox.
	 *
	 * Pre-v9.7.15 this wrote `false` whenever `$_POST['_lafka_variable_in_catalog'][$i]`
	 * was missing — but the hook fires on every variation save, including
	 * programmatic wp_update_post, REST API writes, and bulk operations
	 * (anything that doesn't include the variation-options form fields).
	 * Result: any out-of-band variation save silently flipped "show in catalog"
	 * to OFF for that variation. A single bulk price update destroyed all
	 * catalog visibility flags on every variation it touched.
	 *
	 * Only write when the variation-options form rendered this variation's
	 * field (its hidden `_lafka_variable_in_catalog_field[$i]` marker was
	 * posted) — out-of-band saves leave the existing meta untouched, while an
	 * unticked box (which posts nothing itself) still saves as OFF, even when
	 * it is the last ticked variation being unticked.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $i            Loop index for the variation in the admin form.
	 */
	function lafka_save_variable_in_catalog_option( $variation_id, $i ) {
		// WooCommerce fires this from the AJAX variation save ('save-variations'
		// nonce in `security`) and from the product edit-form save
		// ('woocommerce_save_data' nonce in `woocommerce_meta_nonce`).
		$ajax_ok = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form_ok = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		if ( ( ! $ajax_ok && ! $form_ok ) || ! current_user_can( 'edit_post', (int) $variation_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_lafka_variable_in_catalog_field'][ $i ] ) ) {
			return;
		}
		$show = isset( $_POST['_lafka_variable_in_catalog'][ $i ] );
		update_post_meta( $variation_id, lafka_meta_variable_in_catalog(), $show );
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
			if ( 'lafka_variable_in_catalog_show' === $bulk_action ) {
				update_post_meta( $variation_id, lafka_meta_variable_in_catalog(), true );
			} elseif ( 'lafka_variable_in_catalog_hide' === $bulk_action ) {
				update_post_meta( $variation_id, lafka_meta_variable_in_catalog(), false );
			}
		}
	}
}