<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display-related functions and filters.
 *
 * @class    WC_LafkaCombos_BS_Display
 * @version  6.7.6
 */
class WC_LafkaCombos_BS_Display {

	/**
	 * Setup hooks.
	 */
	public static function init() {

		// Add hooks to display Combo-Sells.
		add_action( 'woocommerce_before_add_to_cart_form', array( __CLASS__, 'add_combo_sells_display_hooks' ) );

		// Item data.
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'combo_sell_data' ), 10, 2 );
	}

	/*
	|--------------------------------------------------------------------------
	| Application layer functions.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Adds logic for overriding combined-item template file locations.
	 *
	 * @return void
	 */
	public static function apply_combined_item_template_overrides() {
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'get_combined_item_template_location' ), 10, 3 );
	}

	/**
	 * Resets all added logic for overriding combined-item template file locations.
	 *
	 * @return void
	 */
	public static function reset_combined_item_template_overrides() {
		remove_filter( 'woocommerce_locate_template', array( __CLASS__, 'get_combined_item_template_location' ), 10, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| Filter/action hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add hooks necessary to display Combo-Sells in single-product templates.
	 */
	public static function add_combo_sells_display_hooks() {

		global $product;

		if ( $product->is_type( 'variable' ) ) {
			add_action( 'woocommerce_single_variation', array( __CLASS__, 'display_combo_sells' ), 19 );
		} else {
			add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'display_combo_sells' ), 1000 );
		}
	}

	/**
	 * Displays Combo-Sells above the add-to-cart button.
	 *
	 * @return void
	 */
	public static function display_combo_sells() {

		global $product;

		$combo_sell_ids = WC_LafkaCombos_BS_Product::get_combo_sell_ids( $product );

		if ( ! empty( $combo_sell_ids ) ) {

			/*
			 * This is not a Combo-type product.
			 * But if it was, then we could re-use the PB templates... without writing new code.
			 * Let's "fake" it.
			 */
			$combo = WC_LafkaCombos_BS_Product::get_combo( $combo_sell_ids, $product );

			if ( ! $combo->get_combined_items() ) {
				return;
			}

			// Syncing at this point will prevent infinite loops in some edge cases.
			$combo->sync();

			if ( false === wp_style_is( 'wc-combo-css', 'enqueued' ) ) {
				wp_enqueue_style( 'wc-combo-css' );
			}

			if ( false === wp_script_is( 'wc-add-to-cart-combo', 'enqueued' ) ) {
				wp_enqueue_script( 'wc-add-to-cart-combo' );
			}

			/*
			 * Show Combo-Sells section title.
			 */
			$combo_sells_title = WC_LafkaCombos_BS_Product::get_combo_sells_title( $product );

			if ( $combo_sells_title ) {

				$combo_sells_title_proc = do_shortcode( wp_kses( $combo_sells_title, WC_LafkaCombos_Helpers::get_allowed_html( 'inline' ) ) );

				wc_get_template(
					'single-product/combo-sells-section-title.php',
					array(
						'wrap'  => $combo_sells_title_proc === $combo_sells_title,
						'title' => $combo_sells_title_proc === $combo_sells_title ? $combo_sells_title_proc : wpautop( $combo_sells_title_proc ),
					),
					false,
					WC_LafkaCombos()->plugin_path() . '/includes/modules/combo-sells/templates/'
				);
			}

			do_action( 'woocommerce_before_combined_items', $combo );

			/*
			 * Show Combo-Sells.
			 */
			?>
			<div class="combo_form combo_sells_form">
			<?php

			foreach ( $combo->get_combined_items() as $combined_item ) {
				// Neat, isn't it?
				self::apply_combined_item_template_overrides();
				do_action( 'woocommerce_combined_item_details', $combined_item, $combo );
				self::reset_combined_item_template_overrides();
			}

			?>
				<div class="combo_data combo_data_<?php echo $combo->get_id(); ?>" data-combo_form_data="<?php echo esc_attr( json_encode( $combo->get_combo_form_data() ) ); ?>" data-combo_id="<?php echo $combo->get_id(); ?>">
					<div class="combo_wrap">
						<div class="combo_error" style="display:none">
							<div class="woocommerce-info">
								<ul class="msg"></ul>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php

			do_action( 'woocommerce_after_combined_items', $combo );
		}
	}

	/**
	 * Filters the default combined-item template file location for use in combo-selling context.
	 *
	 * @param  string  $template
	 * @param  string  $template_name
	 * @param  string  $template_path
	 * @return string
	 */
	public static function get_combined_item_template_location( $template, $template_name, $template_path ) {

		if ( false === strpos( $template_path, WC_LafkaCombos()->plugin_path() . '/includes/modules/combo-sells' ) ) {

			if ( 'single-product/combined-item-quantity.php' === $template_name ) {

				$template = wc_locate_template( 'single-product/combo-sell-quantity.php', '', WC_LafkaCombos()->plugin_path() . '/includes/modules/combo-sells/templates/' );

			} else {

				/**
				 * 'wc_pb_combo_sell_template_name' filter.
				 *
				 * Use this to override the PB templates with new ones when used in Combo-Sells context.
				 *
				 * @param  string  $template_name  Original template name.
				 */
				$template_name_override = apply_filters( 'wc_pb_combo_sell_template_name', $template_name );

				if ( $template_name_override !== $template_name ) {
					$template = wc_locate_template( $template_name_override, '', WC_LafkaCombos()->plugin_path() . '/includes/modules/combo-sells/templates/' );
				}
			}
		}

		return $template;
	}

	/**
	 * Add "Discount applied:" cart item data to combo sells.
	 *
	 * @param  array  $data
	 * @param  array  $cart_item
	 * @return array
	 */
	public static function combo_sell_data( $data, $cart_item ) {

		if ( $parent_item_key = wc_pb_get_combo_sell_cart_item_container( $cart_item, false, true ) ) {

			if ( ! empty( $cart_item['combo_sell_discount'] ) ) {

				$parent_item           = WC()->cart->cart_contents[ $parent_item_key ];
				$parent_item_permalink = apply_filters( 'woocommerce_cart_item_permalink', $parent_item['data']->is_visible() ? $parent_item['data']->get_permalink( $parent_item ) : '', $parent_item, $parent_item_key );
				$parent_item_name      = $parent_item['data']->get_title();

				if ( $parent_item_permalink ) {
					$parent_item_name = wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $parent_item_permalink ), $parent_item_name ), $parent_item, $parent_item_key ) );
				} else {
					$parent_item_name = wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $parent_item_name, $parent_item, $parent_item_key ) );
				}

				/**
				 * Filter combo-sell discount value.
				 *
				 * @since  6.6.0
				 *
				 * @param  array   $cart_item
				 * @param  array   $parent_item
				 * @param  string  $parent_item_name
				 */
				$combo_sell_discount = apply_filters( 'wc_pb_combo_sell_discount_cart_item_meta_value', sprintf( _x( '%s&#37; (applied by %2$s)', 'combo-sell discount', 'lafka-plugin' ), round( (float) $cart_item['combo_sell_discount'], 1 ), $parent_item_name ), $cart_item, $parent_item, $parent_item_name );

				if ( $combo_sell_discount ) {

					$data[] = array(
						'key'   => __( 'Discount', 'lafka-plugin' ),
						'value' => $combo_sell_discount,
					);
				}
			}
		}

		return $data;
	}
}

WC_LafkaCombos_BS_Display::init();
