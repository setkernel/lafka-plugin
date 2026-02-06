<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product meta-box data for the 'Combo' type.
 *
 * @class    WC_LafkaCombos_Meta_Box_Product_Data
 * @version  6.7.4
 */
class WC_LafkaCombos_Meta_Box_Product_Data {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Creates the "Combined Products" tab.
		add_action( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );

		// Creates the panel for selecting combined product options.
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_data_panel' ) );

		// Adds a tooltip to the Manage Stock option.
		add_action( 'woocommerce_product_options_stock', array( __CLASS__, 'stock_note' ) );

		// Add type-specific options.
		add_filter( 'product_type_options', array( __CLASS__, 'combo_type_options' ) );

		// Add Shipping type image select.
		add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'combo_shipping_type_admin_html' ), 10000 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'js_handle_container_classes' ) );

		// Processes and saves type-specific data.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'process_combo_data' ) );

		// Basic combined product admin config options.
		add_action( 'woocommerce_combined_product_admin_config_html', array( __CLASS__, 'combined_product_admin_config_html' ), 10, 4 );

		// Advanced combined product admin config options.
		add_action( 'woocommerce_combined_product_admin_advanced_html', array( __CLASS__, 'combined_product_admin_advanced_html' ), 10, 4 );
		add_action( 'woocommerce_combined_product_admin_advanced_html', array( __CLASS__, 'combined_product_admin_advanced_item_id_html' ), 100, 4 );

		// Combo tab settings.
		add_action( 'woocommerce_combined_products_admin_config', array( __CLASS__, 'combined_products_admin_config_edit_in_cart' ), 20 );
		add_action( 'woocommerce_combined_products_admin_contents', array( __CLASS__, 'combined_products_admin_contents' ), 20 );

		// Extended "Sold Individually" option.
		add_action( 'woocommerce_product_options_sold_individually', array( __CLASS__, 'sold_individually_option' ) );

		/*
		 * Support.
		 */

		// Add a notice if prices not set.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_add_non_purchasable_notice' ), 0 );
	}

	/**
	 * Adds a notice if prices not set.
	 *
	 * @return void
	 */
	public static function maybe_add_non_purchasable_notice() {

		global $post_id;

		// Get admin screen ID.
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( 'product' !== $screen_id ) {
			return;
		}

		$product_type = WC_Product_Factory::get_product_type( $post_id );

		if ( 'combo' !== $product_type ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		if ( false === $product->contains( 'priced_individually' ) && '' === $product->get_price( 'edit' ) ) {
			$notice = sprintf( __( '&quot;%1$s&quot; is not purchasable just yet. But, fear not &ndash; setting up pricing options only takes a minute! <ul class="pb_notice_list"><li>To give &quot;%1$s&quot; a static base price, navigate to <strong>Product Data > General</strong> and fill in the <strong>Regular Price</strong> field.</li><li>To preserve the prices and taxes of individual combined products, go to <strong>Product Data > Combined Products</strong> and enable <strong>Priced Individually</strong> for each combined product whose price must be preserved.</li></ul> Then, save your changes.', 'lafka-plugin' ), $product->get_title());
			WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'warning' );
		}
	}

	/**
	 * Renders extended "Sold Individually" option.
	 *
	 * @return void
	 */
	public static function sold_individually_option() {

		global $product_combo_object;

		$sold_individually         = $product_combo_object->get_sold_individually( 'edit' );
		$sold_individually_context = $product_combo_object->get_sold_individually_context( 'edit' );

		$value = 'no';

		if ( $sold_individually ) {
			if ( ! in_array( $sold_individually_context, array( 'configuration', 'product' ) ) ) {
				$value = 'product';
			} else {
				$value = $sold_individually_context;
			}
		}

		// Provide context to the "Sold Individually" option.
		woocommerce_wp_select( array(
			'id'            => '_wc_pb_sold_individually',
			'wrapper_class' => 'show_if_combo',
			'label'         => __( 'Sold individually', 'woocommerce' ),
			'options'       => array(
				'no'            => __( 'No', 'lafka-plugin' ),
				'product'       => __( 'Yes', 'lafka-plugin' ),
				'configuration' => __( 'Matching configurations only', 'lafka-plugin' )
			),
			'value'         => $value,
			'desc_tip'      => 'true',
			'description'   => __( 'Allow only one of this combo to be bought in a single order. Choose the <strong>Matching configurations only</strong> option to only prevent <strong>identically configured</strong> combos from being purchased together.', 'lafka-plugin' )
		) );
	}

	/**
	 * Add the "Combined Products" panel tab.
	 */
	public static function product_data_tabs( $tabs ) {

		global $post, $product_object, $product_combo_object;

		/*
		 * Create a global combo-type object to use for populating fields.
		 */

		$post_id = $post->ID;

		if ( empty( $product_object ) || false === $product_object->is_type( 'combo' ) ) {
			$product_combo_object = $post_id ? new WC_Product_Combo( $post_id ) : new WC_Product_Combo();
		} else {
			$product_combo_object = $product_object;
		}

		$tabs[ 'combined_products' ] = array(
			'label'    => __( 'Combined Products', 'lafka-plugin' ),
			'target'   => 'combined_product_data',
			'class'    => array( 'show_if_combo', 'wc_gte_26', 'combined_product_options', 'combined_product_tab' ),
			'priority' => 49
		);

		$tabs[ 'inventory' ][ 'class' ][] = 'show_if_combo';

		return $tabs;
	}

	/**
	 * Data panels for Product Combos.
	 */
	public static function product_data_panel() {

		global $product_combo_object;

		?><div id="combined_product_data" class="panel woocommerce_options_panel wc_gte_30" style="display:none">
		    <div class="hr-section hr-section-components"><?php echo __( 'Main Combo Settings', 'lafka-plugin' ); ?></div>
			<div class="options_group_general">
				<?php
				/**
				 * 'woocommerce_combined_products_admin_config' action.
				 *
				 * @param  WC_Product_Combo  $product_combo_object
				 */
				do_action( 'woocommerce_combined_products_admin_config', $product_combo_object );
				?>
			</div>
			<div class="options_group_contents">
				<?php
				/**
				 * 'woocommerce_combined_products_admin_contents' action.
				 *
				 * @since  5.8.0
				 * @param  WC_Product_Combo  $product_combo_object
				 */
				do_action( 'woocommerce_combined_products_admin_contents', $product_combo_object );
				?>
			</div>
		</div><?php
	}

	/**
	 * Add Combined Products stock note.
	 */
	public static function stock_note() {

		global $post;

		?><span class="combo_stock_msg show_if_combo">
				<?php echo wc_help_tip( __( 'By default, the sale of a product within a combo has the same effect on its stock as an individual sale. There are no separate inventory settings for combined items. However, managing stock at combo level can be very useful for allocating combo stock quota, or for keeping track of combined item sales.', 'lafka-plugin' ) ); ?>
		</span><?php
	}

	/**
	 * Product combo type-specific options.
	 *
	 * @param  array  $options
	 * @return array
	 */
	public static function combo_type_options( $options ) {

		$options[ 'downloadable' ][ 'wrapper_class' ] .= ' show_if_combo';
		$options[ 'virtual' ][ 'wrapper_class' ]      .= ' hide_if_combo';

		return $options;
	}

	/**
	 * Shipping type image select html.
	 *
	 * @since 6.0.0
	 *
	 * @return void
	 */
	public static function combo_shipping_type_admin_html() {
		global $product_combo_object, $pagenow;

		$is_new_combo = $pagenow === 'post-new.php';

		$combo_type_options = array(
			array(
				'title'       => __( 'Unassembled', 'lafka-plugin' ),
				'description' => __( 'Combined products preserve their individual dimensions, weight and shipping classes. A virtual container item keeps them grouped together in the cart.', 'lafka-plugin' ),
				'value'       => 'unassembled',
				'checked'     => $is_new_combo || $product_combo_object->is_virtual() ? ' checked="checked"' : ''
			),
			array(
				'title'       => __( 'Assembled', 'lafka-plugin' ),
				'description' => __( 'Combined products are assembled and shipped in a new physical container with the specified dimensions, weight and shipping class. The entire combo appears as a single physical item.</br></br>To ship a combined product outside this container, navigate to the <strong>Combined Products</strong> tab, expand its settings and enable <strong>Shipped Individually</strong>. Combined products that are <strong>Shipped Individually</strong> preserve their own dimensions, weight and shipping classes.', 'lafka-plugin' ),
				'value'       => 'assembled',
				'checked'     => ! $is_new_combo && ! $product_combo_object->is_virtual() ? ' checked="checked"' : ''
			)
		);

		?>
		</div>
		<div class="options_group combo_type show_if_combo">
			<div class="form-field">
				<label><?php esc_html_e( 'Combo type', 'lafka-plugin' ); ?></label>
				<ul class="combo_type_options">
					<?php
					foreach ( $combo_type_options as $type ) {
						$classes = array( $type[ 'value' ] );
						if ( ! empty( $type[ 'checked' ] ) ) {
							$classes[] = 'selected';
						}
						?>
						<li class="<?php echo implode( ' ', $classes ); ?>" >
							<input type="radio"<?php echo $type[ 'checked' ] ?> name="_combo_type" class="combo_type_option" value="<?php echo $type[ 'value' ] ?>">
							<?php echo wc_help_tip( '<strong>' . $type[ 'title' ] . '</strong> &ndash; ' . $type[ 'description' ] ); ?>
						</li>
						<?php
					}
					?>
				</ul>
			</div>
			<div class="wp-clearfix"></div>
			<div id="message" class="inline notice">
				<p>
					<span class="assembled_notice_title"><?php esc_html_e( 'What happened to the shipping options?', 'lafka-plugin' ); ?></span>
					<?php echo __( 'The contents of this combo preserve their dimensions, weight and shipping classes. Unassembled combos do not have a physical container &ndash; or any shipping options to configure.', 'lafka-plugin' ); ?>
				</p>
			</div>
		<?php

		if ( wc_product_weight_enabled() ) {

			woocommerce_wp_select( array(
				'id'            => '_wc_pb_aggregate_weight',
				'wrapper_class' => 'combo_aggregate_weight_field show_if_combo',
				'value'         => $product_combo_object->get_aggregate_weight( 'edit' ) ? 'preserve' : 'ignore',
				'label'         => __( 'Assembled weight', 'lafka-plugin' ),
				'description'   => __( 'Controls whether to ignore or preserve the weight of assembled combined items.</br></br> <strong>Ignore</strong> &ndash; The specified Weight is the total weight of the entire combo.</br></br> <strong>Preserve</strong> &ndash; The specified Weight is treated as a container weight. The total weight of the combo is the sum of: i) the container weight, and ii) the weight of all assembled combined items.', 'lafka-plugin' ),
				'desc_tip'      => true,
				'options'       => array(
					'ignore'        => __( 'Ignore', 'lafka-plugin' ),
					'preserve'      => __( 'Preserve', 'lafka-plugin' ),
				)
			) );
		}
	}

	/**
	 * Renders inline JS to handle product_data container classes.
	 *
	 * @since 6.0.0
	 *
	 * @return void
	 */
	public static function js_handle_container_classes() {

		$js = "
		( function( $ ) {
			$( function() {

				var shipping_product_data = $( '.product_data #shipping_product_data' ),
					virtual_checkbox      = $( 'input#_virtual' ),
					combined_product_data  = $( '.product_data #combined_product_data' ),
					combo_type_options   = shipping_product_data.find( '.combo_type_options li' );

				$( 'body' ).on( 'woocommerce-product-type-change', function( event, select_val ) {

					if ( 'combo' === select_val ) {

						// Force virtual container to always show the shipping tab.
						virtual_checkbox.prop( 'checked', false ).change();

						if ( 'unassembled' === combo_type_options.find( 'input.combo_type_option:checked' ).first().val() ) {
							shipping_product_data.addClass( 'combo_unassembled' );
							combined_product_data.addClass( 'combo_unassembled' );
						}

					} else {
						// Clear container classes.
						shipping_product_data.removeClass( 'combo_unassembled' );
						combined_product_data.removeClass( 'combo_unassembled' );
					}

				} );
			} );
		} )( jQuery );
		";

		// Append right after woocommerce_admin script.
		wp_add_inline_script( 'wc-admin-product-meta-boxes', $js, true );
	}

	/**
	 * Process, verify and save combo type product data.
	 *
	 * @param  WC_Product  $product
	 * @return void
	 */
	public static function process_combo_data( $product ) {

		if ( $product->is_type( 'combo' ) ) {

			/*
			 * Test if 'max_input_vars' limit may have been exceeded.
			 */
			if ( isset( $_POST[ 'pb_post_control_var' ] ) && ! isset( $_POST[ 'pb_post_test_var' ] ) ) {
				$notice = sprintf( __( 'Product Combos has detected that your server may have failed to process and save some of the data on this page. Please get in touch with your server\'s host or administrator and (kindly) ask them to increase the number of variables that PHP scripts can post and process%1$s.', 'lafka-plugin' ), function_exists( 'ini_get' ) && ini_get( 'max_input_vars' ) ? sprintf( __( ' (currently %s)', 'lafka-plugin' ), ini_get( 'max_input_vars' ) ) : '' );
				self::add_admin_notice( $notice, 'warning' );
			}

			$props = array(
				'layout'                    => 'default',
				'group_mode'                => 'parent',
				'editable_in_cart'          => false,
				'aggregate_weight'          => false,
				'sold_individually'         => false,
				'sold_individually_context' => 'product'
			);

			/*
			 * Layout.
			 */

			if ( ! empty( $_POST[ '_wc_pb_layout_style' ] ) ) {
				$props[ 'layout' ] = wc_clean( $_POST[ '_wc_pb_layout_style' ] );
			}

			/*
			 * Item grouping option.
			 */

			$group_mode_pre = $product->get_group_mode( 'edit' );

			if ( ! empty( $_POST[ '_wc_pb_group_mode' ] ) ) {
				$props[ 'group_mode' ] = wc_clean( $_POST[ '_wc_pb_group_mode' ] );
			}

			/*
			 * Cart editing option.
			 */

			if ( ! empty( $_POST[ '_wc_pb_edit_in_cart' ] ) ) {
				$props[ 'editable_in_cart' ] = true;
			}

			/*
			 * Base weight option.
			 */

			if ( ! empty( $_POST[ '_wc_pb_aggregate_weight' ] ) ) {
				$props[ 'aggregate_weight' ] = 'preserve' === $_POST[ '_wc_pb_aggregate_weight' ];
			}

			/*
			 * Extended "Sold Individually" option.
			 */

			if ( ! empty( $_POST[ '_wc_pb_sold_individually' ] ) ) {

				$sold_individually_context = wc_clean( $_POST[ '_wc_pb_sold_individually' ] );

				if ( in_array( $sold_individually_context, array( 'product', 'configuration' ) ) ) {
					$props[ 'sold_individually' ]         = true;
					$props[ 'sold_individually_context' ] = $sold_individually_context;
				}
			}

			/*
			 * "Form location" option.
			 */

			if ( ! empty( $_POST[ '_wc_pb_add_to_cart_form_location' ] ) ) {

				$form_location = wc_clean( $_POST[ '_wc_pb_add_to_cart_form_location' ] );

				if ( in_array( $form_location, array_keys( WC_Product_Combo::get_add_to_cart_form_location_options() ) ) ) {
					$props[ 'add_to_cart_form_location' ] = $form_location;
				}
			}

			/*
			 * Combo shipping type.
			 */
			if ( ! empty( $_POST[ '_combo_type' ] ) ) {
				$props[ 'virtual' ] = 'unassembled' === $_POST[ '_combo_type' ] ? true : false;
			}

			if ( ! defined( 'WC_LafkaCombos_UPDATING' ) ) {

				$posted_combo_data    = isset( $_POST[ 'combo_data' ] ) ? $_POST[ 'combo_data' ] : false; // @phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$processed_combo_data = self::process_posted_combo_data( $posted_combo_data, $product->get_id() );

				if ( empty( $processed_combo_data ) ) {

					self::add_admin_error( __( 'Please add at least one product to the combo before publishing. To add products, click on the <strong>Combined Products</strong> tab.', 'lafka-plugin' ) );
					$props[ 'combined_data_items' ] = array();

				} else {

					foreach ( $processed_combo_data as $key => $data ) {
						$processed_combo_data[ $key ] = array(
							'combined_item_id' => $data[ 'item_id' ],
							'combo_id'       => $product->get_id(),
							'product_id'      => $data[ 'product_id' ],
							'menu_order'      => $data[ 'menu_order' ],
							'meta_data'       => array_diff_key( $data, array( 'item_id' => 1, 'product_id' => 1, 'menu_order' => 1 ) )
						);
					}

					$props[ 'combined_data_items' ] = $processed_combo_data;
				}

				$product->set( $props );

			} else {
				self::add_admin_error( __( 'Your changes have not been saved &ndash; please wait for the <strong>WooCommerce Product Combos Data Update</strong> routine to complete before creating new combos or making changes to existing ones.', 'lafka-plugin' ) );
			}

			/*
			 * Show invalid group mode selection notice.
			 */

			if ( false === $product->validate_group_mode() ) {

				$product->set_group_mode( $group_mode_pre );

				$group_mode_options         = WC_Product_Combo::get_group_mode_options( true );
				$group_modes_without_parent = array();

				foreach ( $group_mode_options as $group_mode_key => $group_mode_title ) {
					if ( false === WC_Product_Combo::group_mode_has( $group_mode_key, 'parent_item' ) ) {
						$group_modes_without_parent[] = '<strong>' . $group_mode_title . '</strong>';
					}
				}

				$group_modes_without_parent_msg = sprintf( _n( '%1$s is only supported by unassembled combos with an empty base price.', '%1$s are only supported by unassembled combos with an emptybase price.', sizeof( $group_modes_without_parent ), 'lafka-plugin' ), WC_LafkaCombos_Helpers::format_list_of_items( $group_modes_without_parent ) );

				self::add_admin_error( sprintf( __( 'The chosen <strong>Item Grouping</strong> option is invalid. %s', 'lafka-plugin' ), $group_modes_without_parent_msg ) );

			}

			/*
			 * Show non-mandatory combo notice.
			 */
			if ( 'none' !== $product->get_group_mode( 'edit' ) && $product->get_combined_items() && ! $product->contains( 'mandatory' ) ) {

				$notice = __( 'This combo does not contain any mandatory items. To control the minimum and/or maximum number of items that customers must choose in this combo, use the <strong>Min Combo Items</strong> and <strong>Max Combo Size</strong> fields under <strong>Product Data > Combined Products</strong>.', 'lafka-plugin' );

				self::add_admin_notice( $notice, array( 'dismiss_class' => 'process_data_min_max', 'type' => 'info' ) );
			}
		}
	}

	/**
	 * Sort by menu order callback.
	 *
	 * @param  array  $a
	 * @param  array  $b
	 * @return int
	 */
	public static function menu_order_sort( $a, $b ) {
		if ( isset( $a[ 'menu_order' ] ) && isset( $b[ 'menu_order' ] ) ) {
			return $a[ 'menu_order' ] - $b[ 'menu_order' ];
		} else {
			return isset( $a[ 'menu_order' ] ) ? 1 : -1;
		}
	}

	/**
	 * Process posted combined item data.
	 *
	 * @param  array  $posted_combo_data
	 * @param  mixed  $post_id
	 * @return mixed
	 */
	public static function process_posted_combo_data( $posted_combo_data, $post_id ) {

		$combo_data = array();

		if ( ! empty( $posted_combo_data ) ) {

			$sold_individually_notices = array();
			$times                     = array();
			$loop                      = 0;

			// Sort posted data by menu order.
			usort( $posted_combo_data, array( __CLASS__, 'menu_order_sort' ) );

			foreach ( $posted_combo_data as $data ) {

				$product_id = isset( $data[ 'product_id' ] ) ? absint( $data[ 'product_id' ] ) : false;
				$item_id    = isset( $data[ 'item_id' ] ) ? absint( $data[ 'item_id' ] ) : false;

				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				$product_type    = $product->get_type();
				$product_title   = $product->get_title();
				$is_subscription = in_array( $product_type, array( 'subscription', 'variable-subscription' ) );

				if ( in_array( $product_type, array( 'simple', 'variable', 'subscription', 'variable-subscription' ) ) && ( $post_id != $product_id ) && ! isset( $sold_individually_notices[ $product_id ] ) ) {

					// Bundling subscription products requires Subs v2.0+.
					if ( $is_subscription ) {
						if ( ! class_exists( 'WC_Subscriptions' ) || version_compare( WC_Subscriptions::$version, '2.0.0', '<' ) ) {
							self::add_admin_error( sprintf( __( '<strong>%s</strong> was not saved. WooCommerce Subscriptions version 2.0 or higher is required in order to combo Subscription products.', 'lafka-plugin' ), $product_title ) );
							continue;
						}
					}

					// Only allow bundling multiple instances of non-sold-individually items.
					if ( ! isset( $times[ $product_id ] ) ) {
						$times[ $product_id ] = 1;
					} else {
						if ( $product->is_sold_individually() ) {
							self::add_admin_error( sprintf( __( '<strong>%s</strong> is sold individually and cannot be combined more than once.', 'lafka-plugin' ), $product_title ) );
							// Make sure we only display the notice once for every id.
							$sold_individually_notices[ $product_id ] = 'yes';
							continue;
						}
						$times[ $product_id ] += 1;
					}

					// Now start processing the posted data.
					$loop++;

					$item_data  = array();
					$item_title = $product_title;

					$item_data[ 'product_id' ] = $product_id;
					$item_data[ 'item_id' ]    = $item_id;

					// Save thumbnail preferences first.
					if ( isset( $data[ 'hide_thumbnail' ] ) ) {
						$item_data[ 'hide_thumbnail' ] = 'yes';
					} else {
						$item_data[ 'hide_thumbnail' ] = 'no';
					}

					// Save title preferences.
					if ( isset( $data[ 'override_title' ] ) ) {
						$item_data[ 'override_title' ] = 'yes';
						$item_data[ 'title' ]          = isset( $data[ 'title' ] ) ? stripslashes( $data[ 'title' ] ) : '';
					} else {
						$item_data[ 'override_title' ] = 'no';
					}

					// Save description preferences.
					if ( isset( $data[ 'override_description' ] ) ) {
						$item_data[ 'override_description' ] = 'yes';
						$item_data[ 'description' ] = isset( $data[ 'description' ] ) ? wp_kses_post( stripslashes( $data[ 'description' ] ) ) : '';
					} else {
						$item_data[ 'override_description' ] = 'no';
					}

					// Save optional.
					if ( isset( $data[ 'optional' ] ) ) {
						$item_data[ 'optional' ] = 'yes';
					} else {
						$item_data[ 'optional' ] = 'no';
					}

					// Save item pricing scheme.
					if ( isset( $data[ 'priced_individually' ] ) ) {
						$item_data[ 'priced_individually' ] = 'yes';
					} else {
						$item_data[ 'priced_individually' ] = 'no';
					}

					// Save item shipping scheme.
					if ( isset( $data[ 'shipped_individually' ] ) || $product->is_virtual() || $is_subscription ) {
						$item_data[ 'shipped_individually' ] = 'yes';
					} else {
						$item_data[ 'shipped_individually' ] = 'no';
					}

					// Save min quantity.
					if ( isset( $data[ 'quantity_min' ] ) ) {

						if ( is_numeric( $data[ 'quantity_min' ] ) ) {

							$quantity = absint( $data[ 'quantity_min' ] );

							if ( $quantity >= 0 && $data[ 'quantity_min' ] - $quantity == 0 ) {

								if ( $quantity > 1 && $product->is_sold_individually() ) {
									self::add_admin_error( sprintf( __( '<strong>%s</strong> is sold individually &ndash; its <strong>Min Quantity</strong> cannot be higher than 1.', 'lafka-plugin' ), $item_title ) );
									$item_data[ 'quantity_min' ] = 1;
								} else {
									$item_data[ 'quantity_min' ] = $quantity;
								}

							} else {
								self::add_admin_error( sprintf( __( 'The minimum quantity of <strong>%s</strong> was not valid and has been reset. Please enter a non-negative integer <strong>Min Quantity</strong> value.', 'lafka-plugin' ), $item_title ) );
								$item_data[ 'quantity_min' ] = 1;
							}
						}

					} else {
						$item_data[ 'quantity_min' ] = 1;
					}

					$quantity_min = $item_data[ 'quantity_min' ];

					// Save max quantity.
					if ( isset( $data[ 'quantity_max' ] ) && ( is_numeric( $data[ 'quantity_max' ] ) || '' === $data[ 'quantity_max' ] ) ) {

						$quantity = '' !== $data[ 'quantity_max' ] ? absint( $data[ 'quantity_max' ] ) : '';

						if ( '' === $quantity || ( $quantity > 0 && $quantity >= $quantity_min && $data[ 'quantity_max' ] - $quantity == 0 ) ) {

							if ( $quantity !== 1 && $product->is_sold_individually() ) {
								self::add_admin_error( sprintf( __( '<strong>%s</strong> is sold individually &ndash; <strong>Max Quantity</strong> cannot be higher than 1.', 'lafka-plugin' ), $item_title ) );
								$item_data[ 'quantity_max' ] = 1;
							} else {
								$item_data[ 'quantity_max' ] = $quantity;
							}

						} else {

							self::add_admin_error( sprintf( __( 'The maximum quantity of <strong>%s</strong> was not valid and has been reset. Please enter a positive integer equal to or higher than <strong>Min Quantity</strong>, or leave the <strong>Max Quantity</strong> field empty for an unlimited maximum quantity.', 'lafka-plugin' ), $item_title ) );

							if ( 0 === $quantity_min ) {
								$item_data[ 'quantity_max' ] = 1;
							} else {
								$item_data[ 'quantity_max' ] = $quantity_min;
							}
						}

					} else {
						$item_data[ 'quantity_max' ] = max( $quantity_min, 1 );
					}

					$quantity_max = $item_data[ 'quantity_max' ];

					// Save default quantity.
					if ( isset( $data[ 'quantity_default' ] ) && is_numeric( $data[ 'quantity_default' ] ) ) {

						$quantity = absint( $data[ 'quantity_default' ] );

						if ( $quantity >= $quantity_min && ( $quantity <= $quantity_max || '' === $quantity_max ) ) {
							$item_data[ 'quantity_default' ] = $quantity;
						} else {
							self::add_admin_error( sprintf( __( 'The default quantity of <strong>%s</strong> was not valid and has been reset. Please enter an integer between the <strong>Min Quantity</strong> and <strong>Max Quantity</strong>.', 'lafka-plugin' ), $item_title ) );
							$item_data[ 'quantity_default' ] = $quantity_min;
						}

					} else {
						$item_data[ 'quantity_default' ] = $quantity_min;
					}

					// Save sale price data.
					if ( isset( $data[ 'discount' ] ) ) {

						if ( 'yes' === $item_data[ 'priced_individually' ] && is_numeric( $data[ 'discount' ] ) ) {

							$discount = wc_format_decimal( $data[ 'discount' ] );

							if ( $discount < 0 || $discount > 100 ) {
								self::add_admin_error( sprintf( __( 'The <strong>Discount</strong> of <strong>%s</strong> was not valid and has been reset. Please enter a positive number between 0-100.', 'lafka-plugin' ), $item_title ) );
								$item_data[ 'discount' ] = '';
							} else {
								$item_data[ 'discount' ] = $discount;
							}
						} else {
							$item_data[ 'discount' ] = '';
						}
					} else {
						$item_data[ 'discount' ] = '';
					}

					// Save data related to variable items.
					if ( in_array( $product_type, array( 'variable', 'variable-subscription' ) ) ) {

						$allowed_variations = array();

						// Save variation filtering options.
						if ( isset( $data[ 'override_variations' ] ) ) {

							if ( isset( $data[ 'allowed_variations' ] ) ) {

								if ( is_array( $data[ 'allowed_variations' ] ) ) {
									$allowed_variations = array_map( 'intval', $data[ 'allowed_variations' ] );
								} else {
									$allowed_variations = array_filter( array_map( 'intval', explode( ',', $data[ 'allowed_variations' ] ) ) );
								}

								if ( count( $allowed_variations ) > 0 ) {

									$item_data[ 'override_variations' ] = 'yes';

									$item_data[ 'allowed_variations' ] = $allowed_variations;

									if ( isset( $data[ 'hide_filtered_variations' ] ) ) {
										$item_data[ 'hide_filtered_variations' ] = 'yes';
									} else {
										$item_data[ 'hide_filtered_variations' ] = 'no';
									}
								}
							} else {
								$item_data[ 'override_variations' ] = 'no';
								self::add_admin_error( sprintf( __( 'Failed to save <strong>Filter Variations</strong> for <strong>%s</strong>. Please choose at least one variation.', 'lafka-plugin' ), $item_title ) );
							}
						} else {
							$item_data[ 'override_variations' ] = 'no';
						}

						// Save defaults.
						if ( isset( $data[ 'override_default_variation_attributes' ] ) ) {

							if ( isset( $data[ 'default_variation_attributes' ] ) ) {

								// If filters are set, check that the selections are valid.
								if ( isset( $data[ 'override_variations' ] ) && ! empty( $allowed_variations ) ) {

									// The array to store all valid attribute options of the iterated product.
									$filtered_attributes = array();

									// Populate array with valid attributes.
									foreach ( $allowed_variations as $variation ) {

										$variation_data = array();

										// Get variation attributes.
										$variation_data = wc_get_product_variation_attributes( $variation );

										foreach ( $variation_data as $name => $value ) {

											$attribute_name  = substr( $name, strlen( 'attribute_' ) );
											$attribute_value = $value;

											// Populate array.
											if ( ! isset( $filtered_attributes[ $attribute_name ] ) ) {
												$filtered_attributes[ $attribute_name ][] = $attribute_value;
											} elseif ( ! in_array( $attribute_value, $filtered_attributes[ $attribute_name ] ) ) {
												$filtered_attributes[ $attribute_name ][] = $attribute_value;
											}
										}
									}

									// Check validity.
									foreach ( $data[ 'default_variation_attributes' ] as $name => $value ) {

										if ( '' === $value ) {
											continue;
										}

										if ( ! in_array( stripslashes( $value ), $filtered_attributes[ $name ] ) && ! in_array( '', $filtered_attributes[ $name ] ) ) {
											// Set option to "Any".
											$data[ 'default_variation_attributes' ][ $name ] = '';
											// Show an error.
											self::add_admin_error( sprintf( __( 'The default variation attribute values of <strong>%s</strong> are inconsistent with the set of active variations and have been reset.', 'lafka-plugin' ), $item_title ) );
											continue;
										}
									}
								}

								// Save.
								foreach ( $data[ 'default_variation_attributes' ] as $name => $value ) {
									$item_data[ 'default_variation_attributes' ][ $name ] = stripslashes( $value );
								}

								$item_data[ 'override_default_variation_attributes' ] = 'yes';
							}

						} else {
							$item_data[ 'override_default_variation_attributes' ] = 'no';
						}
					}

					// Save item visibility preferences.
					$visibility = array(
						'product' => isset( $data[ 'single_product_visibility' ] ) ? 'visible' : 'hidden',
						'cart'    => isset( $data[ 'cart_visibility' ] ) ? 'visible' : 'hidden',
						'order'   => isset( $data[ 'order_visibility' ] ) ? 'visible' : 'hidden'
					);

					if ( 'hidden' === $visibility[ 'product' ] ) {

						if ( in_array( $product_type, array( 'variable', 'variable-subscription' ) ) ) {

							if ( 'yes' === $item_data[ 'override_default_variation_attributes' ] ) {

								if ( ! empty( $data[ 'default_variation_attributes' ] ) ) {

									foreach ( $data[ 'default_variation_attributes' ] as $default_name => $default_value ) {
										if ( '' === $default_value ) {
											$visibility[ 'product' ] = 'visible';
											self::add_admin_error( sprintf( __( 'To hide <strong>%s</strong> from the single-product template, please enable the <strong>Override Default Selections</strong> option and choose default variation attribute values.', 'lafka-plugin' ), $item_title ) );
											break;
										}
									}

								} else {
									$visibility[ 'product' ] = 'visible';
								}

							} else {
								self::add_admin_error( sprintf( __( 'To hide <strong>%s</strong> from the single-product template, please enable the <strong>Override Default Selections</strong> option and choose default variation attribute values.', 'lafka-plugin' ), $item_title ) );
								$visibility[ 'product' ] = 'visible';
							}
						}
					}

					$item_data[ 'single_product_visibility' ] = $visibility[ 'product' ];
					$item_data[ 'cart_visibility' ]           = $visibility[ 'cart' ];
					$item_data[ 'order_visibility' ]          = $visibility[ 'order' ];

					// Save price visibility preferences.

					$item_data[ 'single_product_price_visibility' ] = isset( $data[ 'single_product_price_visibility' ] ) ? 'visible' : 'hidden';
					$item_data[ 'cart_price_visibility' ]           = isset( $data[ 'cart_price_visibility' ] ) ? 'visible' : 'hidden';
					$item_data[ 'order_price_visibility' ]          = isset( $data[ 'order_price_visibility' ] ) ? 'visible' : 'hidden';

					// Save position data.
					$item_data[ 'menu_order' ] = absint( $data[ 'menu_order' ] );

					/**
					 * Filter processed data before saving/updating WC_Combined_Item_Data objects.
					 *
					 * @param  array  $item_data
					 * @param  array  $data
					 * @param  mixed  $item_id
					 * @param  mixed  $post_id
					 */
					$combo_data[] = apply_filters( 'woocommerce_combos_process_combined_item_admin_data', $item_data, $data, $item_id, $post_id );
				}
			}
		}

		return $combo_data;
	}

	/**
	 * Add combined product "Basic" tab content.
	 *
	 * @param  int    $loop
	 * @param  int    $product_id
	 * @param  array  $item_data
	 * @param  int    $post_id
	 * @return void
	 */
	public static function combined_product_admin_config_html( $loop, $product_id, $item_data, $post_id ) {

		$combined_product = isset( $item_data[ 'combined_item' ] ) ? $item_data[ 'combined_item' ]->product : wc_get_product( $product_id );
		$is_subscription = $combined_product->is_type( array( 'subscription', 'variable-subscription' ) );

		if ( in_array( $combined_product->get_type(), array( 'variable', 'variable-subscription' ) ) ) {

			$allowed_variations  = isset( $item_data[ 'allowed_variations' ] ) ? $item_data[ 'allowed_variations' ] : '';
			$default_attributes  = isset( $item_data[ 'default_variation_attributes' ] ) ? $item_data[ 'default_variation_attributes' ] : '';

			$override_variations = isset( $item_data[ 'override_variations' ] ) && 'yes' === $item_data[ 'override_variations' ] ? 'yes' : '';
			$override_defaults   = isset( $item_data[ 'override_default_variation_attributes' ] ) && 'yes' === $item_data[ 'override_default_variation_attributes' ] ? 'yes' : '';

			?><div class="override_variations">
				<div class="form-field">
					<label for="override_variations">
						<?php echo __( 'Filter Variations', 'lafka-plugin' ); ?>
					</label>
					<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $override_variations ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][override_variations]" <?php echo ( 'yes' === $override_variations ? 'value="1"' : '' ); ?>/>
					<?php echo wc_help_tip( __( 'Check to enable only a subset of the available variations.', 'lafka-plugin' ) ); ?>
				</div>
			</div>


			<div class="allowed_variations" <?php echo 'yes' === $override_variations ? '' : 'style="display:none;"'; ?>>
				<div class="form-field"><?php

					$variations = $combined_product->get_children();
					$attributes = $combined_product->get_attributes();

					if ( sizeof( $variations ) < 50 ) {

						?><select multiple="multiple" name="combo_data[<?php echo $loop; ?>][allowed_variations][]" style="width: 95%;" data-placeholder="<?php esc_attr_e( 'Choose variations&hellip;', 'lafka-plugin' ); ?>" class="sw-select2"> <?php

							foreach ( $variations as $variation_id ) {

								if ( is_array( $allowed_variations ) && in_array( $variation_id, $allowed_variations ) ) {
									$selected = 'selected="selected"';
								} else {
									$selected = '';
								}

								$variation_description = WC_LafkaCombos_Helpers::get_product_variation_title( $variation_id, 'flat' );

								if ( ! $variation_description ) {
									continue;
								}

								echo '<option value="' . $variation_id . '" ' . $selected . '>' . $variation_description . '</option>';
							}

						?></select><?php

					} else {

						$allowed_variations_descriptions = array();

						if ( ! empty( $allowed_variations ) ) {

							foreach ( $allowed_variations as $allowed_variation_id ) {

								$variation_description = WC_LafkaCombos_Helpers::get_product_variation_title( $allowed_variation_id, 'flat' );

								if ( ! $variation_description ) {
									continue;
								}

								$allowed_variations_descriptions[ $allowed_variation_id ] = $variation_description;
							}
						}

						?><select class="sw-select2-search--products" multiple="multiple" style="width: 95%;" name="combo_data[<?php echo $loop; ?>][allowed_variations][]" data-placeholder="<?php esc_attr_e( 'Search for variations&hellip;', 'lafka-plugin' ); ?>" data-action="woocommerce_search_combined_variations" data-limit="500" data-include="<?php echo esc_attr( $product_id ); ?>"><?php
							foreach ( $allowed_variations_descriptions as $allowed_variation_id => $allowed_variation_description ) {
								echo '<option value="' . esc_attr( $allowed_variation_id ) . '"' . selected( true, true, false ) . '>' . wp_kses_post( $allowed_variation_description ) . '</option>';
							}
						?></select><?php
					}

				?></div>
			</div>

			<div class="override_default_variation_attributes">
				<div class="form-field">
					<label for="override_default_variation_attributes"><?php echo __( 'Override Default Selections', 'lafka-plugin' ) ?></label>
					<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $override_defaults ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][override_default_variation_attributes]" <?php echo ( 'yes' === $override_defaults ? 'value="1"' : '' ); ?>/>
					<?php echo wc_help_tip( __( 'In effect for this combo only. When <strong>Filter Variations</strong> is enabled, double-check your selections to make sure they correspond to an active variation.', 'lafka-plugin' ) ); ?>
				</div>
			</div>

			<div class="default_variation_attributes" <?php echo 'yes' === $override_defaults ? '' : 'style="display:none;"'; ?>>
				<div class="form-field"><?php

					foreach ( $attributes as $attribute ) {

						if ( ! $attribute->get_variation() ) {
							continue;
						}

						$selected_value = isset( $default_attributes[ sanitize_title( $attribute->get_name() ) ] ) ? $default_attributes[ sanitize_title( $attribute->get_name() ) ] : '';

						?><select name="combo_data[<?php echo $loop; ?>][default_variation_attributes][<?php echo sanitize_title( $attribute->get_name() ); ?>]" data-current="<?php echo esc_attr( $selected_value ); ?>">

							<option value=""><?php echo esc_html( sprintf( __( 'No default %s&hellip;', 'woocommerce' ), wc_attribute_label( $attribute->get_name() ) ) ); ?></option><?php

							if ( $attribute->is_taxonomy() ) {
								foreach ( $attribute->get_terms() as $option ) {
									?><option <?php selected( $selected_value, $option->slug ); ?> value="<?php echo esc_attr( $option->slug ); ?>"><?php echo esc_html( apply_filters( 'woocommerce_variation_option_name', $option->name ) ); ?></option><?php
								}
							} else {
								foreach ( $attribute->get_options() as $option ) {
									?><option <?php selected( $selected_value, $option ); ?> value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( apply_filters( 'woocommerce_variation_option_name', $option ) ); ?></option><?php
								}
							}

						?></select><?php
					}

				?></div>
			</div><?php
		}

		$item_quantity         = isset( $item_data[ 'quantity_min' ] ) ? absint( $item_data[ 'quantity_min' ] ) : 1;
		$item_quantity_max     = $item_quantity;
		$item_quantity_default = $item_quantity;

		if ( isset( $item_data[ 'quantity_max' ] ) ) {
			if ( '' !== $item_data[ 'quantity_max' ] ) {
				$item_quantity_max = absint( $item_data[ 'quantity_max' ] );
			} else {
				$item_quantity_max = '';
			}
		}

		if ( isset( $item_data[ 'quantity_default' ] ) ) {
			$item_quantity_default = absint( $item_data[ 'quantity_default' ] ) ;
		}

		$is_priced_individually  = isset( $item_data[ 'priced_individually' ] ) && 'yes' === $item_data[ 'priced_individually' ] ? 'yes' : '';
		$is_shipped_individually = isset( $item_data[ 'shipped_individually' ] ) && 'yes' === $item_data[ 'shipped_individually' ] ? 'yes' : '';
		$item_discount           = isset( $item_data[ 'discount' ] ) && (double) $item_data[ 'discount' ] > 0 ? $item_data[ 'discount' ] : '';
		$is_optional             = isset( $item_data[ 'optional' ] ) ? $item_data[ 'optional' ] : '';

		// When adding a subscription-type product for the first time, enable "Priced Individually" by default.
		if ( did_action( 'wp_ajax_woocommerce_add_combined_product' ) && $is_subscription && ! isset( $item_data[ 'priced_individually' ] ) ) {
			$is_priced_individually = 'yes';
		}

		?><div class="optional">
			<div class="form-field optional">
				<label for="optional"><?php echo __( 'Optional', 'lafka-plugin' ) ?></label>
				<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $is_optional ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][optional]" <?php echo ( 'yes' === $is_optional ? 'value="1"' : '' ); ?>/>
				<?php echo wc_help_tip( __( 'Check this option to mark the combined product as optional.', 'lafka-plugin' ) ); ?>
			</div>
		</div>

		<div class="quantity_min">
			<div class="form-field">
				<label><?php echo __( 'Min Quantity', 'lafka-plugin' ); ?></label>
				<input type="number" class="item_quantity" size="6" name="combo_data[<?php echo $loop; ?>][quantity_min]" value="<?php echo $item_quantity; ?>" step="any" min="0" />
				<?php echo wc_help_tip( __( 'The minimum quantity of this combined product.', 'lafka-plugin' ) ); ?>
			</div>
		</div>

		<div class="quantity_max">
			<div class="form-field">
				<label><?php echo __( 'Max Quantity', 'lafka-plugin' ); ?></label>
				<input type="number" class="item_quantity" size="6" name="combo_data[<?php echo $loop; ?>][quantity_max]" value="<?php echo $item_quantity_max; ?>" step="any" min="0" />
				<?php echo wc_help_tip( __( 'The maximum quantity of this combined product. Leave the field empty for an unlimited maximum quantity.', 'lafka-plugin' ) ); ?>
			</div>
		</div>

		<div class="quantity_default">
			<div class="form-field">
				<label><?php echo __( 'Default Quantity', 'lafka-plugin' ); ?></label>
				<input type="number" class="item_quantity" size="6" name="combo_data[<?php echo $loop; ?>][quantity_default]" value="<?php echo $item_quantity_default; ?>" step="any" min="0" />
				<?php echo wc_help_tip( __( 'The default quantity of this combined product.', 'lafka-plugin' ) ); ?>
			</div>
		</div>

		<?php if ( $combined_product->needs_shipping() && ! $is_subscription ) : ?>

			<div class="shipped_individually">
				<div class="form-field">
					<label><?php echo __( 'Shipped Individually', 'lafka-plugin' ); ?></label>
					<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $is_shipped_individually ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][shipped_individually]" <?php echo ( 'yes' === $is_shipped_individually ? 'value="1"' : '' ); ?>/>
					<?php echo wc_help_tip( __( 'Check this option if this combined item is shipped separately from the combo.', 'lafka-plugin' ) ); ?>
				</div>
			</div>

		<?php endif; ?>

		<div class="priced_individually">
			<div class="form-field">
				<label><?php echo __( 'Priced Individually', 'lafka-plugin' ); ?></label>
				<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $is_priced_individually ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][priced_individually]" <?php echo ( 'yes' === $is_priced_individually ? 'value="1"' : '' ); ?>/>
				<?php echo wc_help_tip( __( 'Check this option to have the price of this combined item added to the base price of the combo.', 'lafka-plugin' ) ); ?>
			</div>
		</div>

		<div class="discount" <?php echo 'yes' === $is_priced_individually ? '' : 'style="display:none;"'; ?>>
			<div class="form-field">
				<label><?php echo __( 'Discount %', 'lafka-plugin' ); ?></label>
				<input type="text" class="input-text item_discount wc_input_decimal" size="5" name="combo_data[<?php echo $loop; ?>][discount]" value="<?php echo $item_discount; ?>" />
				<?php echo wc_help_tip( __( 'Discount applied to the price of this combined product when Priced Individually is checked. If a Discount is applied to a combined product which has a sale price defined, the sale price will be overridden.', 'lafka-plugin' ) ); ?>
			</div>
		</div><?php
	}

	/**
	 * Add combined product "Advanced" tab content.
	 *
	 * @param  int    $loop
	 * @param  int    $product_id
	 * @param  array  $item_data
	 * @param  int    $post_id
	 * @return void
	 */
	public static function combined_product_admin_advanced_html( $loop, $product_id, $item_data, $post_id ) {

		$is_priced_individually = isset( $item_data[ 'priced_individually' ] ) && 'yes' === $item_data[ 'priced_individually' ];
		$hide_thumbnail         = isset( $item_data[ 'hide_thumbnail' ] ) ? $item_data[ 'hide_thumbnail' ] : '';
		$override_title         = isset( $item_data[ 'override_title' ] ) ? $item_data[ 'override_title' ] : '';
		$override_description   = isset( $item_data[ 'override_description' ] ) ? $item_data[ 'override_description' ] : '';
		$visibility             = array(
			'product' => ! empty( $item_data[ 'single_product_visibility' ] ) && 'hidden' === $item_data[ 'single_product_visibility' ] ? 'hidden' : 'visible',
			'cart'    => ! empty( $item_data[ 'cart_visibility' ] ) && 'hidden' === $item_data[ 'cart_visibility' ] ? 'hidden' : 'visible',
			'order'   => ! empty( $item_data[ 'order_visibility' ] ) && 'hidden' === $item_data[ 'order_visibility' ] ? 'hidden' : 'visible',
		);
		$price_visibility       = array(
			'product' => ! empty( $item_data[ 'single_product_price_visibility' ] ) && 'hidden' === $item_data[ 'single_product_price_visibility' ] ? 'hidden' : 'visible',
			'cart'    => ! empty( $item_data[ 'cart_price_visibility' ] ) && 'hidden' === $item_data[ 'cart_price_visibility' ] ? 'hidden' : 'visible',
			'order'   => ! empty( $item_data[ 'order_price_visibility' ] ) && 'hidden' === $item_data[ 'order_price_visibility' ] ? 'hidden' : 'visible',
		);

		?><div class="item_visibility">
			<div class="form-field">
				<label for="item_visibility"><?php esc_html_e( 'Visibility', 'lafka-plugin' ); ?></label>
				<div>
					<input type="checkbox" class="checkbox visibility_product"<?php echo ( 'visible' === $visibility[ 'product' ] ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][single_product_visibility]" <?php echo ( 'visible' === $visibility[ 'product' ] ? 'value="1"' : '' ); ?>/>
					<span class="labelspan"><?php esc_html_e( 'Product details', 'lafka-plugin' ); ?></span>
					<?php echo wc_help_tip( __( 'Controls the visibility of the combined item in the single-product template of this combo.', 'lafka-plugin' ) ); ?>
				</div>
				<div>
					<input type="checkbox" class="checkbox visibility_cart"<?php echo ( 'visible' === $visibility[ 'cart' ] ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][cart_visibility]" <?php echo ( 'visible' === $visibility[ 'cart' ] ? 'value="1"' : '' ); ?>/>
					<span class="labelspan"><?php esc_html_e( 'Cart/checkout', 'lafka-plugin' ); ?></span>
					<?php echo wc_help_tip( __( 'Controls the visibility of the combined item in cart/checkout templates.', 'lafka-plugin' ) ); ?>
				</div>
				<div>
					<input type="checkbox" class="checkbox visibility_order"<?php echo ( 'visible' === $visibility[ 'order' ] ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][order_visibility]" <?php echo ( 'visible' === $visibility[ 'order' ] ? 'value="1"' : '' ); ?>/>
					<span class="labelspan"><?php esc_html_e( 'Order details', 'lafka-plugin' ); ?></span>
					<?php echo wc_help_tip( __( 'Controls the visibility of the combined item in order-details and e-mail templates.', 'lafka-plugin' ) ); ?>
				</div>
			</div>
		</div>
		<div class="price_visibility" <?php echo $is_priced_individually ? '' : 'style="display:none;"'; ?>>
			<div class="form-field">
				<label for="price_visibility"><?php esc_html_e( 'Price Visibility', 'lafka-plugin' ); ?></label>
				<div class="price_visibility_product_wrapper">
					<input type="checkbox" class="checkbox price_visibility_product"<?php echo ( 'visible' === $price_visibility[ 'product' ] ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][single_product_price_visibility]" <?php echo ( 'visible' === $price_visibility[ 'product' ] ? 'value="1"' : '' ); ?>/>
					<span class="labelspan"><?php esc_html_e( 'Product details', 'lafka-plugin' ); ?></span>
					<?php echo wc_help_tip( __( 'Controls the visibility of the combined-item price in the single-product template of this combo.', 'lafka-plugin' ) ); ?>
				</div>
				<div class="price_visibility_cart_wrapper">
					<input type="checkbox" class="checkbox price_visibility_cart"<?php echo ( 'visible' === $price_visibility[ 'cart' ] ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][cart_price_visibility]" <?php echo ( 'visible' === $price_visibility[ 'cart' ] ? 'value="1"' : '' ); ?>/>
					<span class="labelspan"><?php esc_html_e( 'Cart/checkout', 'lafka-plugin' ); ?></span>
					<?php echo wc_help_tip( __( 'Controls the visibility of the combined-item price in cart/checkout templates.', 'lafka-plugin' ) ); ?>
				</div>
				<div class="price_visibility_order_wrapper">
					<input type="checkbox" class="checkbox price_visibility_order"<?php echo ( 'visible' === $price_visibility[ 'order' ] ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][order_price_visibility]" <?php echo ( 'visible' === $price_visibility[ 'order' ] ? 'value="1"' : '' ); ?>/>
					<span class="labelspan"><?php esc_html_e( 'Order details', 'lafka-plugin' ); ?></span>
					<?php echo wc_help_tip( __( 'Controls the visibility of the combined-item price in order-details and e-mail templates.', 'lafka-plugin' ) ); ?>
				</div>
			</div>
		</div>
		<div class="override_title">
			<div class="form-field override_title">
				<label for="override_title"><?php echo __( 'Override Title', 'lafka-plugin' ) ?></label>
				<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $override_title ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][override_title]" <?php echo ( 'yes' === $override_title ? 'value="1"' : '' ); ?>/>
				<?php echo wc_help_tip( __( 'Check this option to override the default product title.', 'lafka-plugin' ) ); ?>
			</div>
		</div>
		<div class="custom_title">
			<div class="form-field item_title"><?php

				$title = isset( $item_data[ 'title' ] ) ? $item_data[ 'title' ] : '';

				?><textarea name="combo_data[<?php echo $loop; ?>][title]" placeholder="" rows="2" cols="20"><?php echo esc_textarea( $title ); ?></textarea>
			</div>
		</div>
		<div class="override_description">
			<div class="form-field">
				<label for="override_description"><?php echo __( 'Override Short Description', 'lafka-plugin' ) ?></label>
				<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $override_description ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][override_description]" <?php echo ( 'yes' === $override_description ? 'value="1"' : '' ); ?>/>
				<?php echo wc_help_tip( __( 'Check this option to override the default short product description.', 'lafka-plugin' ) ); ?>
			</div>
		</div>
		<div class="custom_description">
			<div class="form-field item_description"><?php

				$description = isset( $item_data[ 'description' ] ) ? $item_data[ 'description' ] : '';

				?><textarea name="combo_data[<?php echo $loop; ?>][description]" placeholder="" rows="2" cols="20"><?php echo esc_textarea( $description ); ?></textarea>
			</div>
		</div>
		<div class="hide_thumbnail">
			<div class="form-field">
				<label for="hide_thumbnail"><?php echo __( 'Hide Thumbnail', 'lafka-plugin' ) ?></label>
				<input type="checkbox" class="checkbox"<?php echo ( 'yes' === $hide_thumbnail ? ' checked="checked"' : '' ); ?> name="combo_data[<?php echo $loop; ?>][hide_thumbnail]" <?php echo ( 'yes' === $hide_thumbnail ? 'value="1"' : '' ); ?>/>
				<?php echo wc_help_tip( __( 'Check this option to hide the thumbnail image of this combined product.', 'lafka-plugin' ) ); ?>
			</div>
		</div><?php
	}

	/**
	 * Add combined item id in "Advanced" tab content.
	 *
	 * @since  5.9.0
	 *
	 * @param  int    $loop
	 * @param  int    $product_id
	 * @param  array  $item_data
	 * @param  int    $post_id
	 * @return void
	 */
	public static function combined_product_admin_advanced_item_id_html( $loop, $product_id, $item_data, $post_id ) {

		if ( ! empty( $item_data[ 'combined_item' ] ) ) {

			?><span class="item-id">
				<?php echo sprintf( _x( 'Item ID: %s', 'combined product id', 'lafka-plugin' ), $item_data[ 'combined_item' ]->get_id() ); ?>
			</span><?php
		}
	}

	/**
	 * Render "Layout" option on 'woocommerce_combined_products_admin_config'.
	 *
	 * @param  WC_Product_Combo  $product_combo_object
	 */
	public static function combined_products_admin_config_layout( $product_combo_object ) {

		woocommerce_wp_select( array(
			'id'            => '_wc_pb_layout_style',
			'wrapper_class' => 'combined_product_data_field',
			'value'         => $product_combo_object->get_layout( 'edit' ),
			'label'         => __( 'Layout', 'lafka-plugin' ),
			'description'   => __( 'Select the <strong>Tabular</strong> option to have the thumbnails, descriptions and quantities of combined products arranged in a table. Recommended for displaying multiple combined products with configurable quantities.', 'lafka-plugin' ),
			'desc_tip'      => true,
			'options'       => WC_Product_Combo::get_layout_options()
		) );
	}

	/**
	 * Displays the "Form Location" option.
	 *
	 * @since  5.8.0
	 *
	 * @param  WC_Product_Combo  $product_combo_object
	 */
	public static function combined_products_admin_config_form_location( $product_combo_object ) {

		$options  = WC_Product_Combo::get_add_to_cart_form_location_options();
		$help_tip = '';
		$loop     = 0;

		foreach ( $options as $option_key => $option ) {

			$help_tip .= '<strong>' . $option[ 'title' ] . '</strong> &ndash; ' . $option[ 'description' ];

			if ( $loop < sizeof( $options ) - 1 ) {
				$help_tip .= '</br></br>';
			}

			$loop++;
		}

		woocommerce_wp_select( array(
			'id'            => '_wc_pb_add_to_cart_form_location',
			'wrapper_class' => 'combined_product_data_field',
			'label'         => __( 'Form Location', 'lafka-plugin' ),
			'options'       => array_combine( array_keys( $options ), wp_list_pluck( $options, 'title' ) ),
			'value'         => $product_combo_object->get_add_to_cart_form_location( 'edit' ),
			'description'   => $help_tip,
			'desc_tip'      => 'true'
		) );
	}

	/**
	 * Render "Item grouping" option on 'woocommerce_combined_products_admin_config'.
	 *
	 * @param  WC_Product_Combo  $product_combo_object
	 */
	public static function combined_products_admin_config_group_mode( $product_combo_object ) {

		$group_mode_options = WC_Product_Combo::get_group_mode_options( true );

		$group_modes_without_parent = array();

		foreach ( $group_mode_options as $group_mode_key => $group_mode_title ) {
			if ( false === WC_Product_Combo::group_mode_has( $group_mode_key, 'parent_item' ) ) {
				$group_modes_without_parent[] = '<strong>' . $group_mode_title . '</strong>';
			}
		}

		woocommerce_wp_select( array(
			'id'            => '_wc_pb_group_mode',
			'wrapper_class' => 'combo_group_mode combined_product_data_field',
			'value'         => $product_combo_object->get_group_mode( 'edit' ),
			'label'         => __( 'Item Grouping', 'lafka-plugin' ),
			'description'   => __( 'Controls the grouping of parent/child line items in cart/order templates.', 'lafka-plugin' ),
			'options'       => $group_mode_options,
			'desc_tip'      => true
		) );
	}

	/**
	 * Render "Edit in Cart" option on 'woocommerce_combined_products_admin_config'.
	 *
	 * @param  WC_Product_Combo  $product_combo_object
	 */
	public static function combined_products_admin_config_edit_in_cart( $product_combo_object ) {

		woocommerce_wp_checkbox( array(
			'id'            => '_wc_pb_edit_in_cart',
			'wrapper_class' => 'combined_product_data_field',
			'label'         => __( 'Edit in Cart', 'lafka-plugin' ),
			'value'         => $product_combo_object->get_editable_in_cart( 'edit' ) ? 'yes' : 'no',
			'description'   => __( 'Whether the combo can be edited in the cart or not.', 'lafka-plugin' ),
			'desc_tip'      => true
		) );
	}

	/**
	 * Render combined product settings on 'woocommerce_combined_products_admin_config'.
	 *
	 * @since  5.8.0
	 *
	 * @param  WC_Product_Combo  $product_combo_object
	 */
	public static function combined_products_admin_contents( $product_combo_object ) {

		$post_id = $product_combo_object->get_id();

		/*
		 * Combined products options.
		 */

		$combined_items = $product_combo_object->get_combined_items( 'edit' );
		$tabs          = self::get_combined_product_tabs();
		$toggle        = 'closed';

		?><div class="hr-section hr-section-components"><?php echo __( 'Combined Products', 'lafka-plugin' ); ?></div>
		<div class="wc-metaboxes-wrapper wc-combo-metaboxes-wrapper <?php echo empty( $combined_items ) ? 'wc-combo-metaboxes-wrapper--boarding' : ''; ?>">

			<div id="wc-combo-metaboxes-wrapper-inner">

				<p class="toolbar">
					<a href="#" class="close_all"><?php esc_html_e( 'Close all', 'woocommerce' ); ?></a>
					<a href="#" class="expand_all"><?php esc_html_e( 'Expand all', 'woocommerce' ); ?></a>
				</p>

				<div class="wc-combined-items wc-metaboxes"><?php

					if ( ! empty( $combined_items ) ) {

						$loop = 0;

						foreach ( $combined_items as $item_id => $item ) {

							$item_availability           = '';
							$item_data                   = $item->get_data();
							$item_data[ 'combined_item' ] = $item;

							$product_id         = $item->get_product_id();
							$title              = $item->product->get_title();
							$sku                = $item->product->get_sku();
							$stock_status       = $item->get_stock_status();
							$stock_status_label = '';

							if ( 'out_of_stock' === $stock_status ) {

								$stock_status_label = __( 'Out of stock', 'woocommerce' );

								if ( $item->get_product()->is_in_stock() ) {
									$stock_status       = 'insufficient_stock';
									$stock_status_label = __( 'Insufficient stock', 'lafka-plugin' );
								}

							} elseif ( 'in_stock' === $stock_status && WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.5' ) ) {

								if ( '' !== $item->get_max_stock() && $item->get_max_stock() <= wc_get_low_stock_amount( $item->get_product() ) ) {
									$stock_status       = 'low_stock';
									$stock_status_label = __( 'Low stock', 'lafka-plugin' );
								}

							} elseif ( 'on_backorder' === $stock_status ) {
								$stock_status_label = __( 'On backorder', 'woocommerce' );
							}

							include( WC_LafkaCombos_ABSPATH . 'includes/admin/meta-boxes/views/html-combined-product.php' );

							$loop++;
						}

					} else {

						?><div class="wc-combined-items__boarding">
							<div class="wc-combined-items__boarding__message">
								<h3><?php esc_html_e( 'Combined Products', 'lafka-plugin' ); ?></h3>
								<p><?php esc_html_e( 'You have not added any products to this combo.', 'lafka-plugin' ); ?>
								<br/><?php esc_html_e( 'Add some now?', 'lafka-plugin' ); ?>
								</p>
							</div>
						</div><?php
					}

				?></div>
			</div>
			<div class="add_combined_product form-field">
				<?php
				/**
				 * 'woocommerce_combined_item_legacy_add_input' filter.
				 *
				 * Filter to include the legacy select2 input instead of the new expanding button.
				 *
				 */
				if ( apply_filters( 'woocommerce_combined_item_legacy_add_input', false ) ) { ?>

					<select class="sw-select2-search--products" id="combined_product" style="width: 250px;" name="combined_product" data-placeholder="<?php esc_attr_e( 'Add a combined product&hellip;', 'lafka-plugin' ); ?>" data-action="woocommerce_json_search_products" multiple="multiple" data-limit="500">
						<option></option>
					</select>

				<?php } else { ?>

					<div class="sw-expanding-button sw-expanding-button--large">
						<span class="sw-title"><?php echo _x( 'Add Product', 'new combined product button', 'lafka-plugin' ); ?></span>
						<select class="sw-select2-search--products" id="combined_product" name="combined_product" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'lafka-plugin' ); ?>" data-action="woocommerce_json_search_products" multiple="multiple" data-limit="500">
							<option></option>
						</select>
					</div>

				<?php } ?>

			</div>
		</div><?php
	}

	/**
	 * Handles getting combined product meta box tabs - @see combined_product_admin_html.
	 *
	 * @return array
	 */
	public static function get_combined_product_tabs() {

		/**
		 * 'woocommerce_combined_product_admin_html_tabs' filter.
		 * Use this to add combined product admin settings tabs
		 *
		 * @param  array  $tab_data
		 */
		return apply_filters( 'woocommerce_combined_product_admin_html_tabs', array(
			array(
				'id'    => 'config',
				'title' => __( 'Basic Settings', 'lafka-plugin' ),
			),
			array(
				'id'    => 'advanced',
				'title' => __( 'Advanced Settings', 'lafka-plugin' ),
			)
		) );
	}

	/**
	 * Add admin notices.
	 *
	 * @param  string  $content
	 * @param  mixed   $args
	 */
	public static function add_admin_notice( $content, $args ) {
		if ( is_array( $args ) && ! empty( $args[ 'dismiss_class' ] ) ) {
			$args[ 'save_notice' ] = true;
			WC_LafkaCombos_Admin_Notices::add_dismissible_notice( $content, $args );
		} else {
			WC_LafkaCombos_Admin_Notices::add_notice( $content, $args, true );
		}
	}

	/**
	 * Add admin errors.
	 *
	 * @param  string  $error
	 * @return string
	 */
	public static function add_admin_error( $error ) {
		self::add_admin_notice( $error, 'error' );
	}

	/*
	|--------------------------------------------------------------------------
	| Deprecated methods.
	|--------------------------------------------------------------------------
	*/

	public static function combined_products_admin_config( $product_combo_object ) {
		_deprecated_function( __METHOD__ . '()', '6.4.0' );
	}
	public static function form_location_option( $product_combo_object ) {
		_deprecated_function( __METHOD__ . '()', '5.8.0', __CLASS__ . '::combined_products_admin_config_form_location()' );
		global $product_combo_object;
		return self::combined_products_admin_config_form_location( $product_combo_object );
	}
	public static function build_combo_config( $post_id, $posted_combo_data ) {
		_deprecated_function( __METHOD__ . '()', '4.11.7', __CLASS__ . '::process_posted_combo_data()' );
		return self::process_posted_combo_data( $posted_combo_data, $post_id );
	}
}

WC_LafkaCombos_Meta_Box_Product_Data::init();
