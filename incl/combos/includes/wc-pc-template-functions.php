<?php
/**
 * Product Combos template functions
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Single-product.
|--------------------------------------------------------------------------
*/

/**
 * Add-to-cart template for Product Combos. Handles the 'Form location > After summary' case.
 *
 * @since  5.7.0
 */
function wc_pc_template_add_to_cart_after_summary() {

	global $product;

	if ( wc_pc_is_product_combo() ) {
		if ( 'after_summary' === $product->get_add_to_cart_form_location() ) {
			$classes = implode( ' ', apply_filters( 'woocommerce_combo_form_wrapper_classes', array( 'summary-add-to-cart-form', 'summary-add-to-cart-form-combo' ), $product ) );
			?><div class="<?php echo esc_attr( $classes );?>"><?php
				do_action( 'woocommerce_combo_add_to_cart' );
			?></div><?php
		}
	}
}

/**
 * Add-to-cart template for Product Combos.
 */
function wc_pc_template_add_to_cart() {

	global $product;

	if ( doing_action( 'woocommerce_single_product_summary' ) ) {
		if ( 'after_summary' === $product->get_add_to_cart_form_location() ) {
			return;
		}
	}

	// Enqueue variation scripts.
	wp_enqueue_script( 'wc-add-to-cart-combo' );

	wp_enqueue_style( 'wc-combo-css' );

	$combined_items = $product->get_combined_items();
	$form_classes  = array( 'layout_' . $product->get_layout(), 'group_mode_' . $product->get_group_mode() );

	if ( ! $product->is_in_stock() ) {
		$form_classes[] = 'combo_out_of_stock';
	}

	if ( 'outofstock' === $product->get_combined_items_stock_status() ) {
		$form_classes[] = 'combo_insufficient_stock';
	}

	if ( ! empty( $combined_items ) ) {

		wc_get_template( 'single-product/add-to-cart/combo.php', array(
			'combined_items'     => $combined_items,
			'product'           => $product,
			'classes'           => implode( ' ', apply_filters( 'woocommerce_combo_form_classes', $form_classes, $product ) ),
			// Back-compat.
			'product_id'        => $product->get_id(),
			'availability_html' => wc_get_stock_html( $product ),
			'combo_price_data' => $product->get_combo_form_data()
		), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
	}
}

/**
 * Add-to-cart buttons area.
 *
 * @since 5.5.0
 *
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_add_to_cart_wrap( $product ) {

	$is_purchasable     = $product->is_purchasable();
	$purchasable_notice = __( 'This product is currently unavailable.', 'lafka-plugin' );

	if ( ! $is_purchasable && current_user_can( 'manage_woocommerce' ) ) {

		$purchasable_notice_reason = '';

		// Give store owners a reason.
		if ( false === $product->contains( 'priced_individually' ) && '' === $product->get_price() ) {
			$purchasable_notice_reason .= sprintf( __( '&quot;%1$s&quot; is not purchasable just yet. But, fear not &ndash; setting up pricing options only takes a minute! <ul class="pb_notice_list"><li>To give &quot;%1$s&quot; a static base price, navigate to <strong>Product Data > General</strong> and fill in the <strong>Regular Price</strong> field.</li><li>To preserve the prices and taxes of individual combined products, go to <strong>Product Data > Combined Products</strong> and enable <strong>Priced Individually</strong> for each combined product whose price must be preserved.</li></ul>Note: This message is visible to store managers only.', 'lafka-plugin' ), $product->get_title() );
		} elseif ( $product->contains( 'non_purchasable' ) ) {
			$purchasable_notice_reason .= __( 'Please make sure that all products contained in this combo have a price. WooCommerce does not allow products with a blank price to be purchased. Note: This message is visible to store managers only.', 'lafka-plugin' );
		} elseif ( $product->contains( 'subscriptions' ) && class_exists( 'WC_Subscriptions_Admin' ) && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {
			$purchasable_notice_reason .= __( 'Please enable <strong>Mixed Checkout</strong> under <strong>WooCommerce > Settings > Subscriptions</strong>. Combos that contain subscription-type products cannot be purchased when <strong>Mixed Checkout</strong> is disabled. Note: This message is visible to store managers only.', 'lafka-plugin' );
		}

		if ( $purchasable_notice_reason ) {
			$purchasable_notice .= '<span class="purchasable_notice_reason">' . $purchasable_notice_reason . '</span>';
		}
	}

	$form_data = $product->get_combo_form_data();

	wc_get_template( 'single-product/add-to-cart/combo-add-to-cart-wrap.php', array(
		'is_purchasable'     => $is_purchasable,
		'purchasable_notice' => $purchasable_notice,
		'availability_html'  => wc_get_stock_html( $product ),
		'combo_form_data'   => $form_data,
		'product'            => $product,
		'product_id'         => $product->get_id(),
		// Back-compat:
		'combo_price_data'  => $form_data,
	), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
}

/**
 * Add-to-cart button and quantity input.
 */
function wc_pc_template_add_to_cart_button( $combo = false ) {

	if ( isset( $_GET[ 'update-combo' ] ) ) {
		$updating_cart_key = wc_clean( $_GET[ 'update-combo' ] );
		if ( isset( WC()->cart->cart_contents[ $updating_cart_key ] ) ) {
			echo '<input type="hidden" name="update-combo" value="' . $updating_cart_key . '" />';
		}
	}

	if ( $combo && ! $combo->is_in_stock() ) {
		return;
	}

	wc_get_template( 'single-product/add-to-cart/combo-quantity-input.php', array(), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
	wc_get_template( 'single-product/add-to-cart/combo-button.php', array(), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
}

/**
 * Load the combined item title template.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_title( $combined_item, $combo ) {

	$min_qty = $combined_item->get_quantity( 'min' );
	$max_qty = $combined_item->get_quantity( 'max' );

	$qty     = 'tabular' !== $combo->get_layout() && $min_qty > 1 && $min_qty === $max_qty ? $min_qty : '';

	wc_get_template( 'single-product/combined-item-title.php', array(
		'quantity'     => $qty,
		'title'        => $combined_item->get_title(),
		'permalink'    => $combined_item->get_permalink(),
		'optional'     => $combined_item->is_optional(),
		'title_suffix' => $combined_item->get_optional_suffix(),
		'combined_item' => $combined_item,
		'combo'       => $combo
	), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
}

/**
 * Load the combined item thumbnail template.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_thumbnail( $combined_item, $combo ) {

	$layout     = $combo->get_layout();
	$product_id = $combined_item->get_product_id();

	if ( 'tabular' === $layout ) {
		echo '<td class="combined_item_col combined_item_images_col">';
	}

	if ( $combined_item->is_visible() ) {
		if ( $combined_item->is_thumbnail_visible() ) {

			/**
			 * 'woocommerce_combined_product_gallery_classes' filter.
			 *
			 * @param  array            $classes
			 * @param  WC_Combined_Item  $combined_item
			 */
			$gallery_classes = apply_filters( 'woocommerce_combined_product_gallery_classes', array( 'combined_product_images', 'images' ), $combined_item );

			/**
			 * 'woocommerce_combined_item_image_tmpl_params' filter.
			 *
			 * @param  array            $params
			 * @param  WC_Combined_Item  $combined_item
			 */
			$combined_item_image_tmpl_params = apply_filters( 'woocommerce_combined_item_image_tmpl_params', array(
				'post_id'         => $product_id,
				'product_id'      => $product_id,
				'combined_item'    => $combined_item,
				'gallery_classes' => $gallery_classes,
				'image_size'      => $combined_item->get_combined_item_thumbnail_size(),
				'image_rel'       => current_theme_supports( 'wc-product-gallery-lightbox' ) ? 'photoSwipe' : 'prettyPhoto',
			), $combined_item );

			wc_get_template( 'single-product/combined-item-image.php', $combined_item_image_tmpl_params, false, WC_LafkaCombos()->plugin_path() . '/templates/' );
		}
	}

	if ( 'tabular' === $layout ) {
		echo '</td>';
	}
}

/**
 * Load the combined item short description template.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_description( $combined_item, $combo ) {

	if ( ! $combined_item->get_description() ) {
		return;
	}

	wc_get_template( 'single-product/combined-item-description.php', array(
		'description' => $combined_item->get_description()
	), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
}

/**
 * Adds the 'combined_product' container div.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_details_wrapper_open( $combined_item, $combo ) {

	$layout = $combo->get_layout();

	if ( ! in_array( $layout, array( 'default', 'tabular', 'grid' ) ) ) {
		return;
	}

	if ( 'default' === $layout ) {
		$el = 'div';
	} elseif ( 'tabular' === $layout ) {
		$el = 'tr';
	} elseif ( 'grid' === $layout ) {
		$el = 'li';
	}

	$classes = $combined_item->get_classes( false );
	$style   = $combined_item->is_visible() ? '' : ' style="display:none;"';

	if ( 'grid' === $layout && $combined_item->is_visible() ) {
		// Get class of item in the grid.
		$classes[] = WC_LafkaCombos()->display->get_grid_layout_class( $combined_item );
		// Increment counter.
		WC_LafkaCombos()->display->incr_grid_layout_pos( $combined_item );
	}

	echo '<' . $el . ' class="' . implode( ' ' , $classes ) . '"' . $style . ' >';
}

/**
 * Adds a qty input column when using the tabular template.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_tabular_combined_item_qty( $combined_item, $combo ) {

	$layout = $combo->get_layout();

	if ( 'tabular' === $layout ) {

		/** Documented in 'WC_LafkaCombos_Cart::get_posted_combo_configuration'. */
		$combo_fields_prefix = apply_filters( 'woocommerce_product_combo_field_prefix', '', $combo->get_id() );

		$quantity_min     = $combined_item->get_quantity( 'min' );
		$quantity_max     = $combined_item->get_quantity( 'max' );
		$quantity_default = $combined_item->get_quantity( 'default' );
		$input_name       = $combo_fields_prefix . 'combo_quantity_' . $combined_item->get_id();
		$hide_input       = $quantity_min === $quantity_max;

		echo '<td class="combined_item_col combined_item_qty_col">';

		wc_get_template( 'single-product/combined-item-quantity.php', array(
			'combined_item'         => $combined_item,
			'quantity_min'         => $quantity_min,
			'quantity_max'         => $quantity_max,
			'quantity_default'     => $quantity_default,
			'input_name'           => $input_name,
			'layout'               => $layout,
			'hide_input'           => $hide_input,
			'combo_fields_prefix' => $combo_fields_prefix
		), false, WC_LafkaCombos()->plugin_path() . '/templates/' );

		echo '</td>';
	}
}

/**
 * Adds a qty input column when using the default template.
 *
 * @param  WC_Combined_Item  $combined_item
 */
function wc_pc_template_default_combined_item_qty( $combined_item ) {

	$combo = $combined_item->get_combo();
	$layout = $combo->get_layout();

	if ( in_array( $layout, array( 'default', 'grid' ) ) ) {

		/** Documented in 'WC_LafkaCombos_Cart::get_posted_combo_configuration'. */
		$combo_fields_prefix = apply_filters( 'woocommerce_product_combo_field_prefix', '', $combo->get_id() );

		$quantity_min     = $combined_item->get_quantity( 'min' );
		$quantity_max     = $combined_item->get_quantity( 'max' );
		$quantity_default = $combined_item->get_quantity( 'default' );
		$input_name       = $combo_fields_prefix . 'combo_quantity_' . $combined_item->get_id();
		$hide_input       = $quantity_min === $quantity_max;
		wc_get_template( 'single-product/combined-item-quantity.php', array(
			'combined_item'         => $combined_item,
			'quantity_min'         => $quantity_min,
			'quantity_max'         => $quantity_max,
			'quantity_default'     => $quantity_default,
			'input_name'           => $input_name,
			'layout'               => $layout,
			'hide_input'           => $hide_input,
			'combo_fields_prefix' => $combo_fields_prefix
		), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
	}
}


/**
 * Close the 'combined_product' container div.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_details_wrapper_close( $combined_item, $combo ) {

	$layout = $combo->get_layout();

	if ( ! in_array( $layout, array( 'default', 'tabular', 'grid' ) ) ) {
		return;
	}

	if ( 'default' === $layout ) {
		$el = 'div';
	} elseif ( 'tabular' === $layout ) {
		$el = 'tr';
	} elseif ( 'grid' === $layout ) {
		$el = 'li';
	}

	echo '</' . $el . '>';
}

/**
 * Add a 'details' container div.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_details_open( $combined_item, $combo ) {

	$layout = $combo->get_layout();

	if ( 'tabular' === $layout ) {
		echo '<td class="combined_item_col combined_item_details_col">';
	}

	echo '<div class="details">';
}

/**
 * Close the 'details' container div.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_details_close( $combined_item, $combo ) {

	$layout = $combo->get_layout();

	echo '</div>';

	if ( 'tabular' === $layout ) {
		echo '</td>';
	}
}

/**
 * Display combined product details templates.
 *
 * @param  WC_Combined_Item    $combined_item
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_combined_item_product_details( $combined_item, $combo ) {

	if ( $combined_item->is_purchasable() ) {

		$combo_id          = $combo->get_id();
		$combined_product    = $combined_item->product;
		$combined_product_id = $combined_product->get_id();
		$availability       = $combined_item->get_availability();

		/** Documented in 'WC_LafkaCombos_Cart::get_posted_combo_configuration'. */
		$combo_fields_prefix = apply_filters( 'woocommerce_product_combo_field_prefix', '', $combo_id );

		$combined_item->add_price_filters();

		if ( $combined_item->is_optional() ) {

			$label_price = '';

			if ( ( $price_html = $combined_item->product->get_price_html() ) && $combined_item->is_priced_individually() ) {

				$label_price_format = __( ' for %s', 'lafka-plugin' );
				$html_from_text_native = wc_get_price_html_from_text();

				if ( false !== strpos( $price_html, $html_from_text_native ) ) {
					$label_price_format = __( ' from %s', 'lafka-plugin' );
					$price_html  = str_replace( $html_from_text_native, '', $price_html );
				}

				$label_price = sprintf( $label_price_format, '<span class="price">' . $price_html . '</span>' );
			}

			$label_title = '';

			if ( $combined_item->get_title() === '' ) {

				$min_quantity = $combined_item->get_quantity( 'min' );
				$max_quantity = $combined_item->get_quantity( 'max' );
				$label_suffix = $min_quantity > 1 && $max_quantity === $min_quantity ? $min_quantity : '';
				$label_title  = sprintf( __( ' &quot;%s&quot;', 'lafka-plugin' ), WC_LafkaCombos_Helpers::format_product_shop_title( $combined_item->get_raw_title(), $label_suffix ) );
			}

			// Optional checkbox template.
			wc_get_template( 'single-product/combined-item-optional.php', array(
				'label_title'          => $label_title,
				'label_price'          => $label_price,
				'combined_item'         => $combined_item,
				'combo_fields_prefix' => $combo_fields_prefix,
				'availability_html'    => false === $combined_item->is_in_stock() ? $combined_item->get_availability_html() : '',
				// Back-compat.
				'quantity'             => $combined_item->get_quantity( 'min' )
			), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
		}

		if ( $combined_product->get_type() === 'simple' || $combined_product->get_type() === 'subscription' ) {

			// Simple Product template.
			wc_get_template( 'single-product/combined-product-simple.php', array(
				'combined_product_id'   => $combined_product_id,
				'combined_product'      => $combined_product,
				'combined_item'         => $combined_item,
				'combo_id'            => $combo_id,
				'combo'               => $combo,
				'combo_fields_prefix' => $combo_fields_prefix,
				'availability'         => $availability,
				'custom_product_data'  => apply_filters( 'woocommerce_combined_product_custom_data', array(), $combined_item )
			), false, WC_LafkaCombos()->plugin_path() . '/templates/' );

		} elseif ( $combined_product->get_type() === 'variable' || $combined_product->get_type() === 'variable-subscription' ) {

			$do_ajax                       = $combined_item->use_ajax_for_product_variations();
			$variations                    = $do_ajax ? false : $combined_item->get_product_variations();
			$variation_attributes          = $combined_item->get_product_variation_attributes();
			$selected_variation_attributes = $combined_item->get_selected_product_variation_attributes();

			if ( ! $do_ajax && empty( $variations ) ) {

				$is_out_of_stock = false === $combined_item->is_in_stock();

				// Unavailable Product template.
				wc_get_template( 'single-product/combined-product-unavailable.php', array(
					'combined_item'        => $combined_item,
					'combo'              => $combo,
					'custom_product_data' => apply_filters( 'woocommerce_combined_product_custom_data', array(
						'is_unavailable'  => 'yes',
						'is_out_of_stock' => $is_out_of_stock ? 'yes' : 'no',
						'is_required'     => $combined_item->get_quantity( 'min', array( 'check_optional' => true ) ) > 0 ? 'yes' : 'no'
					), $combined_item )
				), false, WC_LafkaCombos()->plugin_path() . '/templates/' );

			} else {

				// Variable Product template.
				wc_get_template( 'single-product/combined-product-variable.php', array(
					'combined_product_id'                  => $combined_product_id,
					'combined_product'                     => $combined_product,
					'combined_item'                        => $combined_item,
					'combo_id'                           => $combo_id,
					'combo'                              => $combo,
					'combo_fields_prefix'                => $combo_fields_prefix,
					'availability'                        => $availability,
					'combined_product_attributes'          => $variation_attributes,
					'combined_product_variations'          => $variations,
					'combined_product_selected_attributes' => $selected_variation_attributes,
					'custom_product_data'                 => apply_filters( 'woocommerce_combined_product_custom_data', array(
						'combo_id'       => $combo_id,
						'combined_item_id' => $combined_item->get_id()
					), $combined_item )
				), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
			}
		}

		$combined_item->remove_price_filters();

	} else {
		// Unavailable Product template.
		wc_get_template( 'single-product/combined-product-unavailable.php', array(
			'combined_item'        => $combined_item,
			'combo'              => $combo,
			'custom_product_data' => apply_filters( 'woocommerce_combined_product_custom_data', array(
				'is_unavailable'  => 'yes',
				'is_required'     => $combined_item->get_quantity( 'min', array( 'check_optional' => true ) ) > 0 ? 'yes' : 'no'
			), $combined_item )
		), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
	}
}

/**
 * Combined variation details.
 *
 * @param  int              $product_id
 * @param  WC_Combined_Item  $combined_item
 */
function wc_pc_template_single_variation( $product_id, $combined_item ) {
	?><div class="woocommerce-variation single_variation combined_item_cart_details"></div><?php
}

/**
 * Combined variation template.
 *
 * @since  5.6.0
 *
 * @param  int              $product_id
 * @param  WC_Combined_Item  $combined_item
 */
function wc_pc_template_single_variation_template( $product_id, $combined_item ) {

	wc_get_template( 'single-product/combined-variation.php', array(
		'combined_item'         => $combined_item,
		'combo_fields_prefix' => apply_filters( 'woocommerce_product_combo_field_prefix', '', $combined_item->get_combo_id() ) // Filter documented in 'WC_LafkaCombos_Cart::get_posted_combo_configuration'.
	), false, WC_LafkaCombos()->plugin_path() . '/templates/' );
}

/**
 * Echo opening tabular markup if necessary.
 *
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_before_combined_items( $combo ) {

	$layout = $combo->get_layout();

	if ( 'tabular' === $layout ) {

		$table_classes = array( 'combined_products' );

		if ( false === $combo->contains( 'visible' ) ) {
			$table_classes[] = 'combined_products_hidden';
		}

		/**
		 * 'woocommerce_combos_tabular_classes' filter.
		 *
		 * @since  5.10.1
		 *
		 * @param  array              $classes
		 * @param  WC_Product_Combo  $combo
		 */
		$table_classes = apply_filters( 'woocommerce_combos_tabular_classes', $table_classes, $combo );

		?><table cellspacing="0" class="<?php echo esc_attr( implode( ' ', $table_classes ) ); ?>">
			<thead>
				<th class="combined_item_col combined_item_images_head"></th>
				<th class="combined_item_col combined_item_details_head"><?php esc_html_e( 'Product', 'lafka-plugin' ); ?></th>
				<th class="combined_item_col combined_item_qty_head"><?php esc_html_e( 'Quantity', 'lafka-plugin' ); ?></th>
			</thead>
			<tbody><?php

	} elseif ( 'grid' === $layout ) {

		// Reset grid counter.
		WC_LafkaCombos()->display->reset_grid_layout_pos();

		echo '<ul class="products combined_products columns-' . esc_attr( WC_LafkaCombos()->display->get_grid_layout_columns( $combo ) ) . '">';
	}
}

/**
 * Echo closing tabular markup if necessary.
 *
 * @param  WC_Product_Combo  $combo
 */
function wc_pc_template_after_combined_items( $combo ) {

	$layout = $combo->get_layout();

	if ( 'tabular' === $layout ) {
		echo '</tbody></table>';
	} elseif ( 'grid' === $layout ) {
		echo '</ul>';
	}
}

/**
 * Display combined product attributes.
 *
 * @param  WC_Product  $product
 */
function wc_pc_template_combined_item_attributes( $product ) {

	if ( $product->is_type( 'combo' ) ) {

		$combined_items = $product->get_combined_items();

		if ( ! empty( $combined_items ) ) {

			foreach ( $combined_items as $combined_item ) {

				/** Documented in 'WC_Product_Combo::has_attributes()'. */
				$show_combined_product_attributes = apply_filters( 'woocommerce_combo_show_combined_product_attributes', $combined_item->is_visible(), $product, $combined_item );

				if ( ! $show_combined_product_attributes ) {
					continue;
				}

				$args = $combined_item->get_combined_item_display_attribute_args();

				if ( empty( $args[ 'product_attributes' ] ) ) {
					continue;
				}

				if ( ! WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.6' ) ) {
					add_filter( 'woocommerce_attribute', array( $combined_item, 'filter_combined_item_attribute' ), 10, 3 );
				}

				wc_get_template( 'single-product/combined-item-attributes.php', $args, false, WC_LafkaCombos()->plugin_path() . '/templates/' );

				if ( ! WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.6' ) ) {
					remove_filter( 'woocommerce_attribute', array( $combined_item, 'filter_combined_item_attribute' ), 10, 3 );
				}
			}
		}
	}
}

/**
 * Variation attribute options for combined items. If:
 *
 * - only a single variation is active,
 * - all attributes have a defined value, and
 * - the single values are actually selected as defaults,
 *
 * ...then wrap the dropdown in a hidden div and show the single attribute value description before it.
 *
 * @param  array  $args
 */
function wc_pc_template_combined_variation_attribute_options( $args ) {

	$combined_item                = $args[ 'combined_item' ];
	$variation_attribute_name    = $args[ 'attribute' ];
	$variation_attribute_options = $args[ 'options' ];

	/** Documented in 'WC_LafkaCombos_Cart::get_posted_combo_configuration'. */
	$combo_fields_prefix = apply_filters( 'woocommerce_product_combo_field_prefix', '', $combined_item->get_combo_id() );

	// The currently selected attribute option.
	$selected_option = isset( $_REQUEST[ $combo_fields_prefix . 'combo_attribute_' . sanitize_title( $variation_attribute_name ) . '_' . $combined_item->get_id() ] ) ? wc_clean( wp_unslash( $_REQUEST[ $combo_fields_prefix . 'combo_attribute_' . sanitize_title( $variation_attribute_name ) . '_' . $combined_item->get_id() ] ) ) : $combined_item->get_selected_product_variation_attribute( $variation_attribute_name );

	$variation_attributes              = $combined_item->get_product_variation_attributes();
	$configurable_variation_attributes = $combined_item->get_product_variation_attributes( true );
	$html                              = '';

	// Fill required args.
	$args[ 'selected' ] = $selected_option;
	$args[ 'name' ]     = $combo_fields_prefix . 'combo_attribute_' . sanitize_title( $variation_attribute_name ) . '_' . $combined_item->get_id();
	$args[ 'product' ]  = $combined_item->product;
	$args[ 'id' ]       = sanitize_title( $variation_attribute_name ) . '_' . $combined_item->get_id();

	// Render everything.
	if ( ! $combined_item->display_product_variation_attribute_dropdown( $variation_attribute_name ) ) {

		$variation_attribute_value = '';

		// Get the singular option description.
		if ( taxonomy_exists( $variation_attribute_name ) ) {

			// Get terms if this is a taxonomy.
			$terms = wc_get_product_terms( $combined_item->get_product_id(), $variation_attribute_name, array( 'fields' => 'all' ) );

			foreach ( $terms as $term ) {
				if ( $term->slug === sanitize_title( $selected_option ) ) {
					$variation_attribute_value = esc_html( apply_filters( 'woocommerce_variation_option_name', $term->name ) );
					break;
				}
			}

		} else {

			foreach ( $variation_attribute_options as $option ) {

				if ( sanitize_title( $selected_option ) === $selected_option ) {
					$singular_found = $selected_option === sanitize_title( $option );
				} else {
					$singular_found = $selected_option === $option;
				}

				if ( $singular_found ) {
					$variation_attribute_value = esc_html( apply_filters( 'woocommerce_variation_option_name', $option ) );
					break;
				}
			}
		}

		$html .= '<span class="combined_variation_attribute_value">' . $variation_attribute_value . '</span>';

		// See https://github.com/woothemes/woocommerce/pull/11944 .
		$args[ 'show_option_none' ] = false;

		// Get the dropdowns markup.
		ob_start();
		wc_dropdown_variation_attribute_options( $args );
		$attribute_options = ob_get_clean();

		// Add the dropdown (hidden).
		$html .= '<div class="combined_variation_attribute_options_wrapper" style="display:none;">' . $attribute_options . '</div>';

	} else {

		// Get the dropdowns markup.
		ob_start();
		wc_dropdown_variation_attribute_options( $args );
		$attribute_options = ob_get_clean();

		// Just render the dropdown.
		$html .= $attribute_options;
	}

	if ( sizeof( $configurable_variation_attributes ) === sizeof( $variation_attributes ) ) {
		$variation_attribute_keys = array_keys( $variation_attributes );
		// ...and add the reset-variations link.
		if ( end( $variation_attribute_keys ) === $variation_attribute_name ) {
			// Change 'reset_combined_variations_fixed' to 'reset_combined_variations' if you want the 'Clear' link to slide in/out of view.
			$html .= wp_kses_post( apply_filters( 'woocommerce_reset_variations_link', '<div class="reset_combined_variations_fixed"><a class="reset_variations" href="#">' . esc_html__( 'Clear', 'woocommerce' ) . '</a></div>' ) );
		}
	}

	return $html;
}
