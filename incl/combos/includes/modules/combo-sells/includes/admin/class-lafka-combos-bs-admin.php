<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functions and filters.
 *
 * @class    WC_LafkaCombos_BS_Admin
 * @version  6.1.5
 */
class WC_LafkaCombos_BS_Admin {

	/**
	 * Setup hooks.
	 */
	public static function init() {

		// Display Combo-Sells multi-select.
		add_action( 'woocommerce_product_options_related', array( __CLASS__, 'combo_sells_options' ) );

		// Save posted Combo-Sells.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'process_combo_sells_options' ) );

		// Ajax search combo-sells. Only simple products are allowed for now.
		add_action( 'wp_ajax_woocommerce_json_search_combo_sells', array( __CLASS__, 'ajax_search_combo_sells' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Filter hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Display Combo-Sells multiselect.
	 */
	public static function combo_sells_options() {

		global $product_object;

		?>
		<div class="options_group hide_if_grouped hide_if_external hide_if_combo">
			<p class="form-field ">
				<label for="combo_sell_ids"><?php esc_html_e( 'Combo-sells', 'lafka-plugin' ); ?></label>
				<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="combo_sell_ids" name="combo_sell_ids[]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'woocommerce' ); ?>" data-action="woocommerce_json_search_combo_sells" data-exclude="<?php echo intval( $product_object->get_id() ); ?>" data-limit="100" data-sortable="true">
					<?php

						$product_ids = WC_LafkaCombos_BS_Product::get_combo_sell_ids( $product_object, 'edit' );

						if ( ! empty( $product_ids ) ) {
							foreach ( $product_ids as $product_id ) {

								$product = wc_get_product( $product_id );

								if ( is_object( $product ) ) {
									echo '<option value="' . esc_attr( $product_id ) . '"' . selected( true, true, false ) . '>' . wp_kses_post( $product->get_formatted_name() ) . '</option>';
								}
							}
						}
					?>
				</select> <?php 

					$supported_product_types_disclaimer = class_exists( 'WC_Subscriptions' ) ? __( 'Supported product types: Simple, Simple subscription.', 'lafka-plugin' ) : __( 'Supports Simple products only.', 'lafka-plugin' );
					echo wc_help_tip( sprintf( __( 'Combo-sells are optional products that can be selected and added to the cart along with this product. %s', 'lafka-plugin' ), $supported_product_types_disclaimer ) );
				?>
				<span class="combo-sells-search-description"><?php echo class_exists( 'WC_Subscriptions' ) ? __( 'Supported product types: Simple, Simple subscription.', 'lafka-plugin' ) : __( 'Supports Simple products only.', 'lafka-plugin' ); ?></span>
			</p>
			<?php

				woocommerce_wp_textarea_input( array(
					'id'            => 'wc_pb_combo_sells_title',
					'value'         => WC_LafkaCombos_BS_Product::get_combo_sells_title( $product_object, 'edit' ),
					'label'         => __( 'Combo-sells title', 'lafka-plugin' ),
					'description'   => __( 'Text to display above the combo-sells section.', 'lafka-plugin' ),
					'placeholder'   => __( 'e.g. "Frequently Bought Together"', 'lafka-plugin' ),
					'desc_tip'      => true
				) );

				woocommerce_wp_text_input( array(
					'id'            => 'wc_pb_combo_sells_discount',
					'value'         => WC_LafkaCombos_BS_Product::get_combo_sells_discount( $product_object, 'edit' ),
					'type'          => 'text',
					'class'         => 'input-text wc_input_decimal',
					'label'         => __( 'Combo-sells discount', 'lafka-plugin' ),
					'description'   => __( 'Discount to apply to combo-sells (%). Accepts values from 0 to 100.', 'lafka-plugin' ),
					'desc_tip'      => true
				) );

			?>
		</div>
		<?php
	}

	/**
	 * Process and save posted Combo-Sells.
	 */
	public static function process_combo_sells_options( $product ) {

		/*
		 * Process combo-sell IDs.
		 */

		$combo_sell_ids = ! empty( $_POST[ 'combo_sell_ids' ] ) && is_array( $_POST[ 'combo_sell_ids' ] ) ? array_map( 'intval', (array) $_POST[ 'combo_sell_ids' ] ) : array();

		if ( ! empty( $combo_sell_ids ) ) {
			$product->update_meta_data( '_wc_pb_combo_sell_ids', $combo_sell_ids );
		} else {
			$product->delete_meta_data( '_wc_pb_combo_sell_ids' );
		}

		/*
		 * Process combo-sells title.
		 */

		$title = ! empty( $_POST[ 'wc_pb_combo_sells_title' ] ) ? wp_kses( wp_unslash( $_POST[ 'wc_pb_combo_sells_title' ] ), WC_LafkaCombos_Helpers::get_allowed_html( 'inline' ) ) : false; // @phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $title ) {
			$product->update_meta_data( '_wc_pb_combo_sells_title',  $title );
		} else {
			$product->delete_meta_data( '_wc_pb_combo_sells_title' );
		}

		/*
		 * Process combo-sells discount.
		 */

		$discount = ! empty( $_POST[ 'wc_pb_combo_sells_discount' ] ) ? sanitize_text_field( $_POST[ 'wc_pb_combo_sells_discount' ] ) : false;

		if ( ! empty( $discount ) ) {

			if ( is_numeric( $discount ) ) {
				$discount = wc_format_decimal( $discount );
			} else {
				$discount = -1;
			}

			if ( $discount < 0 || $discount > 100 ) {
				$discount = false;
				WC_LafkaCombos_Meta_Box_Product_Data::add_admin_error( __( 'Invalid combo-sells discount value. Please enter a positive number between 0-100.', 'lafka-plugin' ) );
			}
		}

		if ( $discount ) {
			$product->update_meta_data( '_wc_pb_combo_sells_discount', $discount );
		} else {
			$product->delete_meta_data( '_wc_pb_combo_sells_discount' );
		}
	}

	/**
	 * Ajax search for combined variations.
	 */
	public static function ajax_search_combo_sells() {

		add_filter( 'woocommerce_json_search_found_products', array( __CLASS__, 'filter_ajax_search_results' ) );
		WC_AJAX::json_search_products( '', false );
		remove_filter( 'woocommerce_json_search_found_products', array( __CLASS__, 'filter_ajax_search_results' ) );
	}

	/**
	 * Include only simple products in combo-sell results.
	 *
	 * @param  array  $search_results
	 * @return array
	 */
	public static function filter_ajax_search_results( $search_results ) {

		if ( ! empty( $search_results ) ) {

			$search_results_filtered = array();

			foreach ( $search_results as $product_id => $product_title ) {

				$product = wc_get_product( $product_id );

				if ( is_object( $product ) && $product->is_type( array( 'simple', 'subscription' ) ) ) {
					$search_results_filtered[ $product_id ] = $product_title;
				}
			}

			$search_results = $search_results_filtered;
		}

		return $search_results;
	}
}

WC_LafkaCombos_BS_Admin::init();
