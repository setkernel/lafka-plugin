<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Combo display functions and filters.
 *
 * @class    WC_LafkaCombos_Display
 * @version  6.7.0
 */
class WC_LafkaCombos_Display {

	/**
	 * Indicates whether the combined table item indent JS has already been enqueued.
	 * @var boolean
	 */
	private $enqueued_combined_table_item_js = false;

	/**
	 * Workaround for $order arg missing from 'woocommerce_order_item_name' filter - set within the 'woocommerce_order_item_class' filter - @see 'order_item_class()'.
	 * @var boolean|WC_Order
	 */
	private $order_item_order = false;

	/**
	 * Active element position/column when rendering a grid of combined items, applicable when the "Grid" layout is active.
	 * @var integer
	 */
	private $grid_layout_pos = 1;

	/**
	 * Runtime cache.
	 * @var bool
	 */
	private $display_cart_prices_incl_tax;

	/**
	 * The single instance of the class.
	 * @var WC_LafkaCombos_Display
	 *
	 * @since 5.0.0
	 */
	protected static $_instance = null;

	/**
	 * Main WC_LafkaCombos_Display instance. Ensures only one instance of WC_LafkaCombos_Display is loaded or can be loaded.
	 *
	 * @since  5.0.0
	 *
	 * @return WC_LafkaCombos_Display
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 5.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '5.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 5.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '5.0.0' );
	}

	/**
	 * Setup hooks and functions.
	 */
	protected function __construct() {

		// Single product template functions and hooks.
		require_once( WC_LafkaCombos_ABSPATH . 'includes/wc-pc-template-functions.php' );
		require_once( WC_LafkaCombos_ABSPATH . 'includes/wc-pc-template-hooks.php' );

		// Front end combo add-to-cart script.
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ), 100 );

		/*
		 * Single-product.
		 */

		// Display info notice when editing a combo from the cart. Notices are rendered at priority 10.
		add_action( 'woocommerce_before_single_product', array( $this, 'add_edit_in_cart_notice' ), 0 );

		// Modify structured data.
		add_filter( 'woocommerce_structured_data_product_offer', array( $this, 'structured_product_data' ), 10, 2 );

		// Replace 'in_stock' post class with 'insufficient_stock' and 'out_of_stock' post class.
		add_filter( 'woocommerce_post_class', array( $this, 'post_classes' ), 10, 2 );

		/*
		 * Cart.
		 */

		// Filter cart item price.
		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price' ), 10, 3 );

		// Filter cart item subtotals.
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'cart_item_subtotal' ), 10, 3 );
		add_filter( 'woocommerce_checkout_item_subtotal', array( $this, 'cart_item_subtotal' ), 10, 3 );

		// Keep quantities in sync.
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'cart_item_quantity' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'cart_item_remove_link' ), 10, 2 );

		// Visibility.
		add_filter( 'woocommerce_cart_item_visible', array( $this, 'cart_item_visible' ), 10, 3 );
		add_filter( 'woocommerce_widget_cart_item_visible', array( $this, 'cart_item_visible' ), 10, 3 );
		add_filter( 'woocommerce_checkout_cart_item_visible', array( $this, 'cart_item_visible' ), 10, 3 );

		// Modify titles.
		add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_title' ), 10, 3 );

		// Add table item classes.
		add_filter( 'woocommerce_cart_item_class', array( $this, 'cart_item_class' ), 10, 3 );

		// Filter cart item count.
		add_filter( 'woocommerce_cart_contents_count',  array( $this, 'cart_contents_count' ) );

		// Item data.
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data' ), 10, 2 );

		// Hide thumbnail in cart when 'Hide thumbnail' option is selected.
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'cart_item_thumbnail' ), 10, 3);

		// Filter cart widget items.
		add_action( 'woocommerce_before_mini_cart', array( $this, 'add_cart_widget_filters' ) );
		add_action( 'woocommerce_after_mini_cart', array( $this, 'remove_cart_widget_filters' ) );

		/*
		 * Orders.
		 */

		// Filter order item subtotals.
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( $this, 'order_item_subtotal' ), 10, 3 );

		// Visibility.
		add_filter( 'woocommerce_order_item_visible', array( $this, 'order_item_visible' ), 10, 2 );

		// Modify titles.
		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_title' ), 10, 2 );

		// Add table item classes.
		add_filter( 'woocommerce_order_item_class', array( $this, 'order_item_class' ), 10, 3 );

		// Filter order item count.
		add_filter( 'woocommerce_get_item_count', array( $this, 'order_item_count' ), 10, 3 );

		// Indentation of combined items in emails.
		add_action( 'woocommerce_email_styles', array( $this, 'email_styles' ) );

		/*
		 * Archives.
		 */

		// Allow ajax add-to-cart to work in WC 2.3/2.4.
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_add_to_cart_link' ), 10, 2 );
	}

	/**
	 * Frontend scripts.
	 *
	 * @return void
	 */
	public function frontend_scripts() {

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'wc-add-to-cart-combo', WC_LafkaCombos()->plugin_url() . '/assets/js/frontend/add-to-cart-combo' . $suffix . '.js', array( 'jquery', 'wc-add-to-cart-variation' ), WC_LafkaCombos()->version, true );

		wp_register_style( 'wc-combo-css', WC_LafkaCombos()->plugin_url() . '/assets/css/frontend/single-product.css', false, WC_LafkaCombos()->version );
		wp_style_add_data( 'wc-combo-css', 'rtl', 'replace' );

		wp_register_style( 'wc-combo-style', WC_LafkaCombos()->plugin_url() . '/assets/css/frontend/woocommerce.css', false, WC_LafkaCombos()->version );
		wp_style_add_data( 'wc-combo-style', 'rtl', 'replace' );

		wp_enqueue_style( 'wc-combo-style' );

		/**
		 * 'woocommerce_combo_front_end_params' filter.
		 *
		 * @param  array
		 */
		$params = apply_filters( 'woocommerce_combo_front_end_params', array(
			'i18n_free'                      => __( 'Free!', 'woocommerce' ),
			'i18n_total'                     => __( 'Total: ', 'lafka-plugin' ),
			'i18n_subtotal'                  => __( 'Subtotal: ', 'lafka-plugin' ),
			'i18n_price_format'              => sprintf( _x( '%1$s%2$s%3$s', '"Total/Subtotal" string followed by price followed by price suffix', 'lafka-plugin' ), '%t', '%p', '%s' ),
			'i18n_strikeout_price_string'    => sprintf( _x( '<del>%1$s</del> <ins>%2$s</ins>', 'Sale/strikeout price', 'lafka-plugin' ), '%f', '%t' ),
			'i18n_insufficient_stock_list'   => sprintf( _x( '<p class="stock out-of-stock insufficient-stock">%1$s &rarr; %2$s</p>', 'insufficiently stocked items template', 'lafka-plugin' ), __( 'Insufficient stock', 'lafka-plugin' ), '%s' ),
			'i18n_on_backorder_list'         => sprintf( _x( '<p class="stock available-on-backorder">%1$s &rarr; %2$s</p>', 'backordered items template', 'lafka-plugin' ), __( 'Available on backorder', 'woocommerce' ), '%s' ),
			'i18n_insufficient_stock_status' => sprintf( _x( '<p class="stock out-of-stock insufficient-stock">%s</p>', 'insufficiently stocked item exists template', 'lafka-plugin' ), __( 'Insufficient stock', 'lafka-plugin' ) ),
			'i18n_on_backorder_status'       => sprintf( _x( '<p class="stock available-on-backorder">%s</p>', 'backordered item exists template', 'lafka-plugin' ), __( 'Available on backorder', 'woocommerce' ) ),
			'i18n_select_options'            => __( 'Please choose product options.', 'lafka-plugin' ),
			'i18n_select_options_for'        => __( 'Please choose %s options.', 'lafka-plugin' ),
			'i18n_enter_valid_price'         => __( 'Please enter valid amounts.', 'lafka-plugin' ),
			'i18n_enter_valid_price_for'     => __( 'Please enter a valid %s amount.', 'lafka-plugin' ),
			'i18n_string_list_item'          => _x( '&quot;%s&quot;', 'string list item', 'lafka-plugin' ),
			'i18n_string_list_sep'           => sprintf( _x( '%1$s, %2$s', 'string list item separator', 'lafka-plugin' ), '%s', '%v' ),
			'i18n_string_list_last_sep'      => sprintf( _x( '%1$s and %2$s', 'string list item last separator', 'lafka-plugin' ), '%s', '%v' ),
			'i18n_qty_string'                => _x( ' &times; %s', 'qty string', 'lafka-plugin' ),
			'i18n_optional_string'           => _x( ' &mdash; %s', 'suffix', 'lafka-plugin' ),
			'i18n_optional'                  => __( 'optional', 'lafka-plugin' ),
			'i18n_contents'                  => __( 'Includes', 'lafka-plugin' ),
			'i18n_title_meta_string'         => sprintf( _x( '%1$s &ndash; %2$s', 'title followed by meta', 'lafka-plugin' ), '%t', '%m' ),
			'i18n_title_string'              => sprintf( _x( '%1$s%2$s%3$s%4$s', 'title, quantity, price, suffix', 'lafka-plugin' ), '<span class="item_title">%t</span>', '<span class="item_qty">%q</span>', '', '<span class="item_suffix">%o</span>' ),
			'i18n_unavailable_text'          => __( 'This product is currently unavailable.', 'lafka-plugin' ),
			'i18n_validation_alert'          => __( 'Please resolve all pending issues before adding this product to your cart.', 'lafka-plugin' ),
			'i18n_zero_qty_error'            => __( 'Please choose at least 1 item.', 'lafka-plugin' ),
			'i18n_recurring_price_join'      => sprintf( _x( '%1$s,</br>%2$s', 'subscription price html', 'lafka-plugin' ), '%r', '%c' ),
			'i18n_recurring_price_join_last' => sprintf( _x( '%1$s, and</br>%2$s', 'subscription price html', 'lafka-plugin' ), '%r', '%c' ),
			'discounted_price_decimals'      => WC_LafkaCombos_Product_Prices::get_discounted_price_precision(),
			'currency_symbol'                => get_woocommerce_currency_symbol(),
			'currency_position'              => esc_attr( stripslashes( get_option( 'woocommerce_currency_pos' ) ) ),
			'currency_format_num_decimals'   => wc_pc_price_num_decimals(),
			'currency_format_decimal_sep'    => esc_attr( stripslashes( get_option( 'woocommerce_price_decimal_sep' ) ) ),
			'currency_format_thousand_sep'   => esc_attr( stripslashes( get_option( 'woocommerce_price_thousand_sep' ) ) ),
			'currency_format_trim_zeros'     => false === apply_filters( 'woocommerce_price_trim_zeros', false ) ? 'no' : 'yes',
			'price_display_suffix'           => esc_attr( get_option( 'woocommerce_price_display_suffix' ) ),
			'prices_include_tax'             => esc_attr( get_option( 'woocommerce_prices_include_tax' ) ),
			'tax_display_shop'               => esc_attr( get_option( 'woocommerce_tax_display_shop' ) ),
			'calc_taxes'                     => esc_attr( get_option( 'woocommerce_calc_taxes' ) ),
			'photoswipe_enabled'             => current_theme_supports( 'wc-product-gallery-lightbox' ) ? 'yes' : 'no',
			'responsive_breakpoint'          => 380,
			'zoom_enabled'                   => 'no',
			'force_min_max_qty_input'        => 'yes'
		) );

		wp_localize_script( 'wc-add-to-cart-combo', 'wc_combo_params', $params );
	}

	/**
	 * Enqeue js that wraps combined table items in a div in order to apply indentation reliably.
	 * This obviously sucks but if you can find a CSS-only way to do it better that works reliably with any theme out there, drop us a line, will you?
	 *
	 * @return void
	 */
	private function enqueue_combined_table_item_js() {

		/**
		 * 'woocommerce_combined_table_item_js_enqueued' filter.
		 *
		 * Use this filter to get rid of this ugly hack:
		 * Return 'false' and add your own CSS to indent '.combined_table_item' elements.
		 *
		 * @since  5.5.0
		 *
		 * @param  boolean  $is_enqueued
		 */
		$is_enqueued = apply_filters( 'woocommerce_combined_table_item_js_enqueued', $this->enqueued_combined_table_item_js );

		if ( ! $is_enqueued ) {

			$js = "
				jQuery( function( $ ) {
					var wc_pb_wrap_combined_table_item = function() {
						$( '.combined_table_item td.product-name' ).each( function() {
							var el = $( this );
							if ( el.find( '.combined-product-name' ).length === 0 ) {
								el.wrapInner( '<div class=\"combined-product-name combined_table_item_indent\"></div>' );
							}
						} );
					};

					$( 'body' ).on( 'updated_checkout updated_cart_totals', function() {
						wc_pb_wrap_combined_table_item();
					} );

					wc_pb_wrap_combined_table_item();
				} );
			";

			wp_add_inline_script( 'woocommerce', $js );

			$this->enqueued_combined_table_item_js = true;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Single-product.
	|--------------------------------------------------------------------------
	*/

	/**
	 * The number of combined item columns when the "Grid" layout is active.
	 *
	 * @since  5.8.0
	 *
	 * @param  WC_Product_Combo  $combo
	 * @return int
	 */
	public function get_grid_layout_columns( $combo ) {

		/**
		 * 'woocommerce_combined_items_grid_columns' filter.
		 *
		 * @since  5.8.0
		 *
		 * @param  int                $count
		 * @param  WC_Product_Combo  $combo
		 */
		return apply_filters( 'woocommerce_combined_items_grid_layout_columns', 3, $combo );
	}

	/**
	 * Class associated with the position of a combined item in the grid when the "Grid" layout is active.
	 *
	 * @since  5.8.0
	 *
	 * @param  WC_Combined_Item  $combined_item
	 * @return int
	 */
	public function get_grid_layout_class( $combined_item ) {

		$class = '';

		if ( $this->grid_layout_pos === 1 ) {
			$class = 'first';
		} elseif ( $this->grid_layout_pos === $this->get_grid_layout_columns( $combined_item->get_combo() ) ) {
			$class = 'last';
		}

		return $class;
	}

	/**
	 * Increments the position of a combined item in the grid when the "Grid" layout is active.
	 *
	 * @since  5.8.0
	 *
	 * @param  WC_Combined_Item  $combined_item
	 * @return int
	 */
	public function incr_grid_layout_pos( $combined_item ) {

		if ( $this->grid_layout_pos === $this->get_grid_layout_columns( $combined_item->get_combo() ) ) {
			$this->grid_layout_pos = 1;
		} else {
			$this->grid_layout_pos++;
		}
	}

	/**
	 * Resets the position of a combined item in the grid when the "Grid" layout is active.
	 *
	 * @since  5.8.0
	 *
	 * @return void
	 */
	public function reset_grid_layout_pos() {
		$this->grid_layout_pos = 1;
	}

	/**
	 * Display info notice when editing a combo from the cart.
	 */
	public function add_edit_in_cart_notice() {

		global $product;

		if ( $product->is_type( 'combo' ) && isset( $_GET[ 'update-combo' ] ) ) {
			$updating_cart_key = wc_clean( $_GET[ 'update-combo' ] );
			if ( isset( WC()->cart->cart_contents[ $updating_cart_key ] ) ) {
				$notice = sprintf ( __( 'You are currently editing &quot;%1$s&quot;. When finished, click the <strong>Update Cart</strong> button.', 'lafka-plugin' ), $product->get_title() );
				wc_add_notice( $notice, 'notice' );
			}
		}
	}

	/**
	 * Modify structured data for combo-type products.
	 *
	 * @param  array       $data
	 * @param  WC_Product  $product
	 * @return array
	 */
	public function structured_product_data( $data, $product ) {

		if ( is_object( $product ) && $product->is_type( 'combo' ) ) {

			$combo_price = $product->get_combo_price();

			if ( isset( $data[ 'price' ] ) ) {
				$data[ 'price' ] = $combo_price;
			}

			if ( isset( $data[ 'priceSpecification' ][ 'price' ] ) ) {
				$data[ 'priceSpecification' ][ 'price' ] = $combo_price;
			}
		}

		return $data;
	}

	/**
	 * Replace 'in_stock' post class with 'insufficient_stock' and 'out_of_stock' post class.
	 *
	 * @since  5.11.2
	 *
	 * @param  array       $classes
	 * @param  WC_Product  $product
	 * @return array
	 */
	public function post_classes( $classes, $product ) {

		if ( ! $product->is_type( 'combo' ) ) {
			return $classes;
		}

		if ( in_array( 'instock', $classes ) && 'outofstock' === $product->get_combined_items_stock_status() ) {
			$classes = array_diff( $classes, array( 'instock' ) );
			$classes = array_merge( $classes, array( 'outofstock', 'insufficientstock' ) );
		}

		return $classes;
	}

	/*
	|--------------------------------------------------------------------------
	| Cart.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Back-compat wrapper for 'WC_Cart::display_price_including_tax'.
	 *
	 * @since  6.3.2
	 *
	 * @return string
	 */
	private function display_cart_prices_including_tax() {

		if ( is_null( $this->display_cart_prices_incl_tax ) ) {
			$this->display_cart_prices_incl_tax = WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.3' ) ? WC()->cart->display_prices_including_tax() : ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) );
		}

		return $this->display_cart_prices_incl_tax;
	}

	/**
	 * Outputs a formatted subtotal.
	 *
	 * @param  WC_Product  $product
	 * @param  string      $subtotal
	 * @return string
	 */
	public function format_subtotal( $product, $subtotal ) {

		$cart               = WC()->cart;
		$taxable            = $product->is_taxable();
		$formatted_subtotal = wc_price( $subtotal );

		if ( $taxable ) {

			$tax_subtotal = WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.2' ) ? $cart->get_subtotal_tax() : $cart->tax_total;

			if ( ! $this->display_cart_prices_including_tax() ) {

				if ( wc_prices_include_tax() && $tax_subtotal > 0 ) {
					$formatted_subtotal .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
				}

			} else {

				if ( ! wc_prices_include_tax() && $tax_subtotal > 0 ) {
					$formatted_subtotal .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
				}
			}
		}

		return $formatted_subtotal;
	}

	/**
	 * Modify the front-end price of combined items and container items depending on their pricing setup.
	 *
	 * @param  double  $price
	 * @param  array   $values
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function cart_item_price( $price, $cart_item, $cart_item_key ) {

		if ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {
			$price = $this->get_child_cart_item_price( $price, $cart_item, $cart_item_key, $combo_container_item );
		} elseif ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {
			$price = $this->get_container_cart_item_price( $price, $cart_item, $cart_item_key );
		}

		return $price;
	}

	/**
	 * Modifies child cart item prices.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $price
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function get_child_cart_item_price( $price, $cart_item, $cart_item_key, $combo_container_item = false ) {

		if ( false === $combo_container_item ) {
			$combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item );
		}

		if ( $combo_container_item ) {

			$combined_item_id = $cart_item[ 'combined_item_id' ];

			if ( $combined_item = $combo_container_item[ 'data' ]->get_combined_item( $combined_item_id ) ) {

				if ( empty( $cart_item[ 'line_subtotal' ] ) && false === $combined_item->is_priced_individually() ) {

					$price = '';

				} elseif ( false === $combined_item->is_price_visible( 'cart' ) ) {

					$price = '';

				} elseif ( WC_Product_Combo::group_mode_has( $combo_container_item[ 'data' ]->get_group_mode(), 'aggregated_prices' ) ) {

					if ( WC_LafkaCombos()->compatibility->is_composited_cart_item( $combo_container_item ) ) {
						$price = '';
					} elseif ( $price ) {
						$price = '<span class="combined_' . ( $this->is_cart_widget() ? 'mini_cart' : 'table' ) . '_item_price">' . $price . '</span>';
					}

				} elseif ( $price && function_exists( 'wc_cp_get_composited_cart_item_container' ) && ( $composite_container_item_key = wc_cp_get_composited_cart_item_container( $combo_container_item, WC()->cart->cart_contents, true ) ) ) {

					$composite_container_item = WC()->cart->cart_contents[ $composite_container_item_key ];

					if ( apply_filters( 'woocommerce_add_composited_cart_item_prices', true, $composite_container_item, $composite_container_item_key ) ) {

						$show_price = true;

						if ( empty( $cart_item[ 'line_subtotal' ] ) && false === $combined_item->is_priced_individually() ) {

							$component_id             = $combo_container_item[ 'composite_item' ];
							$composite_container_item = wc_cp_get_composited_cart_item_container( $combo_container_item );

							if ( $composite_container_item ) {
								$component  = $composite_container_item[ 'data' ]->get_component( $component_id );
								$show_price = $component && $component->is_priced_individually();
							}
						}

						if ( $show_price ) {
							$price = '<span class="combined_' . ( $this->is_cart_widget() ? 'mini_cart' : 'table' ) . '_item_price">' . $price . '</span>';
						} else {
							$price = '';
						}
					}
				}
			}
		}

		return $price;
	}

	/**
	 * Aggregates parent + child cart item prices.
	 *
	 * @param  string  $price
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	private function get_container_cart_item_price( $price, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			$aggregate_prices = WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'aggregated_prices' );

			if ( $aggregate_prices ) {

				$calc_type           = ! $this->display_cart_prices_including_tax() ? 'excl_tax' : 'incl_tax';
				$combo_price        = WC_LafkaCombos_Product_Prices::get_product_price( $cart_item[ 'data' ], array( 'price' => $cart_item[ 'data' ]->get_price(), 'calc' => $calc_type ) );
				$combined_cart_items  = wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents );
				$combined_items_price = 0.0;

				foreach ( $combined_cart_items as $combined_cart_item ) {

					$combined_item_id        = $combined_cart_item[ 'combined_item_id' ];
					$combined_item_raw_price = $combined_cart_item[ 'data' ]->get_price();

					if ( WC_LafkaCombos()->compatibility->is_subscription( $combined_cart_item[ 'data' ] ) ) {

						$combined_item = $cart_item[ 'data' ]->get_combined_item( $combined_item_id );

						if ( $combined_item ) {
							$combined_item_raw_recurring_fee = $combined_cart_item[ 'data' ]->get_price();
							$combined_item_raw_sign_up_fee   = (float) WC_Subscriptions_Product::get_sign_up_fee( $combined_cart_item[ 'data' ] );
							$combined_item_raw_price         = $combined_item->get_up_front_subscription_price( $combined_item_raw_recurring_fee, $combined_item_raw_sign_up_fee, $combined_cart_item[ 'data' ] );
						}
					}

					$combined_item_qty     = $combined_cart_item[ 'data' ]->is_sold_individually() ? 1 : $combined_cart_item[ 'quantity' ] / $cart_item[ 'quantity' ];
					$combined_item_price   = WC_LafkaCombos_Product_Prices::get_product_price( $combined_cart_item[ 'data' ], array( 'price' => $combined_item_raw_price, 'calc' => $calc_type, 'qty' => $combined_item_qty ) );
					$combined_items_price += wc_format_decimal( (float) $combined_item_price, wc_pc_price_num_decimals() );
				}

				$price = wc_price( (float) $combo_price + $combined_items_price );

			} elseif ( empty( $cart_item[ 'line_subtotal' ] ) ) {

				$combined_items          = wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents );
				$combined_item_subtotals = wp_list_pluck( $combined_items, 'line_subtotal' );

				if ( array_sum( $combined_item_subtotals ) > 0 ) {
					$price = '';
				}
			}
		}

		return $price;
	}

	/**
	 * Modifies child cart item subtotals.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $price
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function get_child_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key, $combo_container_item = false ) {

		if ( false === $combo_container_item ) {
			$combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item );
		}

		if ( $combo_container_item ) {

			$combined_item_id = $cart_item[ 'combined_item_id' ];

			if ( $combined_item = $combo_container_item[ 'data' ]->get_combined_item( $combined_item_id ) ) {

				if ( empty( $cart_item[ 'line_subtotal' ] ) && false === $combined_item->is_priced_individually() ) {

					$subtotal = '';

				} elseif ( false === $combined_item->is_price_visible( 'cart' ) ) {

					$subtotal = '';

				} elseif ( WC_Product_Combo::group_mode_has( $combo_container_item[ 'data' ]->get_group_mode(), 'aggregated_subtotals' ) ) {

					if ( WC_LafkaCombos()->compatibility->is_composited_cart_item( $combo_container_item ) ) {
						$subtotal = '';
					} elseif ( $subtotal ) {
						$subtotal = '<span class="combined_' . ( $this->is_cart_widget() ? 'mini_cart' : 'table' ) . '_item_subtotal">' . sprintf( _x( '%1$s: %2$s', 'combined product subtotal', 'lafka-plugin' ), __( 'Subtotal', 'lafka-plugin' ), $subtotal ) . '</span>';
					}

				} elseif ( $subtotal && function_exists( 'wc_cp_get_composited_cart_item_container' ) && ( $composite_container_item_key = wc_cp_get_composited_cart_item_container( $combo_container_item, WC()->cart->cart_contents, true ) ) ) {

					$composite_container_item = WC()->cart->cart_contents[ $composite_container_item_key ];

					if ( apply_filters( 'woocommerce_add_composited_cart_item_subtotals', true, $composite_container_item, $composite_container_item_key ) ) {

						$show_subtotal = true;

						if ( empty( $cart_item[ 'line_subtotal' ] ) && false === $combined_item->is_priced_individually() ) {

							$component_id             = $combo_container_item[ 'composite_item' ];
							$composite_container_item = wc_cp_get_composited_cart_item_container( $combo_container_item );

							if ( $composite_container_item ) {
								$component     = $composite_container_item[ 'data' ]->get_component( $component_id );
								$show_subtotal = $component && $component->is_priced_individually();
							}
						}

						if ( $show_subtotal ) {
							$subtotal = '<span class="combined_' . ( $this->is_cart_widget() ? 'mini_cart' : 'table' ) . '_item_subtotal">' . sprintf( _x( '%1$s: %2$s', 'combined product subtotal', 'lafka-plugin' ), __( 'Subtotal', 'lafka-plugin' ), $subtotal ) . '</span>';
						} else {
							$subtotal = '';
						}
					}
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Aggregates parent + child cart item subtotals.
	 *
	 * @param  string  $subtotal
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	private function get_container_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			$aggregate_subtotals = WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'aggregated_subtotals' );

			if ( $aggregate_subtotals ) {

				$calc_type           = ! $this->display_cart_prices_including_tax() ? 'excl_tax' : 'incl_tax';
				$combo_price        = WC_LafkaCombos_Product_Prices::get_product_price( $cart_item[ 'data' ], array( 'price' => $cart_item[ 'data' ]->get_price(), 'calc' => $calc_type, 'qty' => $cart_item[ 'quantity' ] ) );
				$combined_cart_items  = wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents );
				$combined_items_price = 0.0;

				foreach ( $combined_cart_items as $combined_cart_item ) {

					$combined_item_id        = $combined_cart_item[ 'combined_item_id' ];
					$combined_item_raw_price = $combined_cart_item[ 'data' ]->get_price();

					if ( WC_LafkaCombos()->compatibility->is_subscription( $combined_cart_item[ 'data' ] ) ) {

						$combined_item = $cart_item[ 'data' ]->get_combined_item( $combined_item_id );

						if ( $combined_item ) {
							$combined_item_raw_recurring_fee = $combined_cart_item[ 'data' ]->get_price();
							$combined_item_raw_sign_up_fee   = (float) WC_Subscriptions_Product::get_sign_up_fee( $combined_cart_item[ 'data' ] );
							$combined_item_raw_price         = $combined_item->get_up_front_subscription_price( $combined_item_raw_recurring_fee, $combined_item_raw_sign_up_fee, $combined_cart_item[ 'data' ] );
						}
					}

					$combined_item_price    = WC_LafkaCombos_Product_Prices::get_product_price( $combined_cart_item[ 'data' ], array( 'price' => $combined_item_raw_price, 'calc' => $calc_type, 'qty' => $combined_cart_item[ 'quantity' ] ) );
					$combined_items_price  += wc_format_decimal( (float) $combined_item_price, wc_pc_price_num_decimals() );
				}

				$subtotal = $this->format_subtotal( $cart_item[ 'data' ], (float) $combo_price + $combined_items_price );

			} elseif ( empty( $cart_item[ 'line_subtotal' ] ) ) {

				$combined_items          = wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents );
				$combined_item_subtotals = wp_list_pluck( $combined_items, 'line_subtotal' );

				if ( array_sum( $combined_item_subtotals ) > 0 ) {
					$subtotal = '';
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Modifies line item subtotals in the 'cart.php' & 'review-order.php' templates.
	 *
	 * @param  string  $subtotal
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combined_cart_item( $cart_item ) ) {
			$subtotal = $this->get_child_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key );
		} elseif ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {
			$subtotal = $this->get_container_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key );
		}

		return $subtotal;
	}

	/**
	 * Combined item quantities can't be changed individually. When adjusting quantity for the container item, the combined products must follow.
	 *
	 * @param  int     $quantity
	 * @param  string  $cart_item_key
	 * @return int
	 */
	public function cart_item_quantity( $quantity, $cart_item_key ) {

		$cart_item = WC()->cart->cart_contents[ $cart_item_key ];

		if ( $container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			$combined_item_id = $cart_item[ 'combined_item_id' ];
			$combined_item    = $container_item[ 'data' ]->get_combined_item( $combined_item_id );

			$min_quantity = $combined_item->get_quantity( 'min' );
			$max_quantity = $combined_item->get_quantity( 'max' );

			if ( $min_quantity === $max_quantity ) {

				$quantity = $cart_item[ 'quantity' ];

			} else {

				$parent_quantity = $container_item[ 'quantity' ];

				$min_qty = $parent_quantity * $min_quantity;
				$max_qty = '' !== $max_quantity ? $parent_quantity * $max_quantity : '';

				if ( ( $max_qty > $min_qty || '' === $max_qty ) && ! $cart_item[ 'data' ]->is_sold_individually() ) {

					$quantity = woocommerce_quantity_input( array(
						'input_name'  => "cart[{$cart_item_key}][qty]",
						'input_value' => $cart_item[ 'quantity' ],
						'min_value'   => $min_qty,
						'max_value'   => $max_qty,
						'step'        => $parent_quantity
					), $cart_item[ 'data' ], false );

				} else {
					$quantity = $cart_item[ 'quantity' ];
				}
			}
		}

		return $quantity;
	}

	/**
	 * Combined items can't be removed individually from the cart - this hides the remove buttons.
	 *
	 * @param  string  $link
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function cart_item_remove_link( $link, $cart_item_key ) {

		$cart_item = WC()->cart->cart_contents[ $cart_item_key ];

		if ( $combo_container_item_key = wc_pc_get_combined_cart_item_container( $cart_item, false, true ) ) {

			$combo_container_item = WC()->cart->cart_contents[ $combo_container_item_key ];

			$combo = $combo_container_item[ 'data' ];

			if ( false === WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'parent_item' ) ) {

				/*
				 * If it's the first child, show a button that relays the remove action to the parent.
				 * Here we assume that the first child is visible.
				 */
				$combined_cart_item_keys = wc_pc_get_combined_cart_items( $combo_container_item, false, true );

				if ( empty( $combined_cart_item_keys ) || current( $combined_cart_item_keys ) !== $cart_item_key ) {
					return '';
				} else {
					$link = sprintf(
						'<a href="%s" class="remove remove_combo" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
						esc_url( WC_LafkaCombos_Core_Compatibility::wc_get_cart_remove_url( $combo_container_item_key ) ),
						__( 'Remove this item', 'woocommerce' ),
						esc_attr( $combo->get_id() ),
						esc_attr( $combo->get_sku() )
					);
				}

			} else {
				return '';
			}
		}

		return $link;
	}

	/**
	 * Visibility of combined item in cart.
	 *
	 * @param  boolean  $visible
	 * @param  array    $cart_item
	 * @param  string   $cart_item_key
	 * @return boolean
	 */
	public function cart_item_visible( $visible, $cart_item, $cart_item_key ) {

		if ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			$combo          = $combo_container_item[ 'data' ];
			$combined_item_id = $cart_item[ 'combined_item_id' ];

			if ( $combined_item = $combo->get_combined_item( $combined_item_id ) ) {
				if ( false === $combined_item->is_visible( 'cart' ) ) {
					$visible = false;
				}
			}

		} elseif ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			$combo = $cart_item[ 'data' ];

			if ( false === WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'parent_item' ) ) {
				$visible = false;
			}
		}

		return $visible;
	}

	/**
	 * Override combined item title in cart/checkout templates.
	 *
	 * @param  string  $content
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function cart_item_title( $content, $cart_item, $cart_item_key ) {

		if ( $combo_container_item_key = wc_pc_get_combined_cart_item_container( $cart_item, false, true ) ) {

			$combo_container_item = WC()->cart->cart_contents[ $combo_container_item_key ];
			$combo                = $combo_container_item[ 'data' ];

			if ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'child_item_indent' ) ) {
				$this->enqueue_combined_table_item_js();
			}

			if ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'faked_parent_item' ) ) {

				$combined_cart_item_keys = wc_pc_get_combined_cart_items( $combo_container_item, false, true );

				if ( ! empty( $combined_cart_item_keys ) && current( $combined_cart_item_keys ) === $cart_item_key ) {

					if ( function_exists( 'is_cart' ) && is_cart() && ! $this->is_cart_widget() ) {

						if ( $combo->is_editable_in_cart( $combo_container_item ) ) {

							$edit_in_cart_link = esc_url( add_query_arg( array( 'update-combo' => $combo_container_item_key ), $combo->get_permalink( $combo_container_item ) ) );
							$edit_in_cart_text = _x( 'Edit', 'edit in cart link text', 'lafka-plugin' );
							$content           = sprintf( _x( '%1$s<br/><a class="edit_combo_in_cart_text edit_in_cart_text" rel="no-follow" href="%2$s"><small>%3$s</small></a>', 'edit in cart text', 'lafka-plugin' ), $content, $edit_in_cart_link, $edit_in_cart_text );
						}
					}
				}
			}

		} elseif ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			$combo = $cart_item[ 'data' ];

			if ( function_exists( 'is_cart' ) && is_cart() && ! $this->is_cart_widget() ) {

				if ( $combo->is_editable_in_cart( $cart_item ) ) {

					$edit_in_cart_link = esc_url( add_query_arg( array( 'update-combo' => $cart_item_key ), $combo->get_permalink( $cart_item ) ) );
					$edit_in_cart_text = _x( 'Edit', 'edit in cart link text', 'lafka-plugin' );
					$content           = sprintf( _x( '%1$s<br/><a class="edit_combo_in_cart_text edit_in_cart_text" href="%2$s"><small>%3$s</small></a>', 'edit in cart text', 'lafka-plugin' ), $content, $edit_in_cart_link, $edit_in_cart_text );
				}

				if ( WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'parent_cart_item_meta' ) ) {
					$content .= $this->get_combo_container_cart_item_data( $cart_item, true );
				}
			}
		}

		return $content;
	}

	/**
	 * Change the tr class of combined items in cart templates to allow their styling.
	 *
	 * @param  string  $classname
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public function cart_item_class( $classname, $cart_item, $cart_item_key ) {

		if ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			$combo = $combo_container_item[ 'data' ];

			if ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'child_item_indent' ) ) {

				if ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'faked_parent_item' ) ) {

					// Ensure this isn't the first child (shamelessly assuming that the first one is visible).
					$combined_cart_item_keys = wc_pc_get_combined_cart_items( $combo_container_item, false, true );

					if ( empty( $combined_cart_item_keys ) || current( $combined_cart_item_keys ) !== $cart_item_key ) {
						$classname .= ' combined_table_item';
					}

				} else {
					$classname .= ' combined_table_item';
				}
			}

		} elseif ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {
			$classname .= ' combo_table_item';
		}

		return $classname;
	}

	/**
	 * Filters the reported number of cart items.
	 *
	 * @param  int  $count
	 * @return int
	 */
	public function cart_contents_count( $count ) {

		$cart     = WC()->cart->get_cart();
		$subtract = 0;

		foreach ( $cart as $cart_item_key => $cart_item ) {
			if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

				$parent_item_visible = $this->cart_item_visible( true, $cart_item, $cart_item_key );

				if ( ! $parent_item_visible ) {
					$subtract += $cart_item[ 'quantity' ];
				}

				$combined_cart_items = wc_pc_get_combined_cart_items( $cart_item );

				foreach ( $combined_cart_items as $combined_item_key => $combined_cart_item ) {

					$subtract_combined_item_qty = $parent_item_visible || false === $this->cart_item_visible( true, $combined_cart_item, $combined_item_key );

					if ( $subtract_combined_item_qty ) {
						$subtract += $combined_cart_item[ 'quantity' ];
					}
				}
			}
		}

		return $count - $subtract;
	}

	/**
	 * Add "Part of" cart item data to combined items.
	 *
	 * @param  array  $data
	 * @param  array  $cart_item
	 * @return array
	 */
	public function cart_item_data( $data, $cart_item ) {

		if ( $container = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			$combo = $container[ 'data' ];

			if ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'child_item_meta' ) ) {
				$data[] = array(
					'key'   => __( 'Part of', 'lafka-plugin' ),
					'value' => $combo->get_title()
				);
			}
		}

		return $data;
	}

	/**
	 * Hide thumbnail in cart when 'Hide thumbnail' option is selected.
	 *
	 * @param  string  $image
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */

	public function cart_item_thumbnail( $image, $cart_item, $cart_item_key ) {

		if ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			$combined_item_id = $cart_item[ 'combined_item_id' ];

			if ( $combined_item = $combo_container_item[ 'data' ]->get_combined_item( $combined_item_id) ) {

				if ( false === $combined_item->is_thumbnail_visible() ) {

					$is_faked_parent_item = false;

					if ( WC_Product_Combo::group_mode_has( $combo_container_item[ 'data' ]->get_group_mode(), 'faked_parent_item' ) ) {

						$combined_cart_item_keys = wc_pc_get_combined_cart_items( $combo_container_item, false, true );

						if ( ! empty( $combined_cart_item_keys ) && current( $combined_cart_item_keys ) === $cart_item_key ) {
							$is_faked_parent_item = true;
						}
					}

					if ( ! $is_faked_parent_item ) {
						$image = '';
					}
				}
			}
		}

		return $image;
	}

	/**
	 * Rendering cart widget?
	 *
	 * @since  5.8.0
	 * @return boolean
	 */
	protected function is_cart_widget() {
		return did_action( 'woocommerce_before_mini_cart' ) > did_action( 'woocommerce_after_mini_cart' );
	}

	/**
	 * Add cart widget filters.
	 *
	 * @return void
	 */
	public function add_cart_widget_filters() {
		add_filter( 'woocommerce_mini_cart_item_class', array( $this, 'mini_cart_item_class' ), 10, 2 );
		add_filter( 'woocommerce_widget_cart_item_visible', array( $this, 'cart_widget_item_visible' ), 10, 3 );
		add_filter( 'woocommerce_widget_cart_item_quantity', array( $this, 'cart_widget_item_qty' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_widget_container_item_name' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_widget_container_item_data' ), 10, 2 );
	}

	/**
	 * Remove cart widget filters.
	 *
	 * @return void
	 */
	public function remove_cart_widget_filters() {
		remove_filter( 'woocommerce_mini_cart_item_class', array( $this, 'mini_cart_item_class' ), 10, 2 );
		remove_filter( 'woocommerce_widget_cart_item_visible', array( $this, 'cart_widget_item_visible' ), 10, 3 );
		remove_filter( 'woocommerce_widget_cart_item_quantity', array( $this, 'cart_widget_item_qty' ), 10, 3 );
		remove_filter( 'woocommerce_cart_item_name', array( $this, 'cart_widget_container_item_name' ), 10, 3 );
		remove_filter( 'woocommerce_get_item_data', array( $this, 'cart_widget_container_item_data' ), 10, 2 );
	}

	/**
	 * Change the li class of composite parent/child items in mini-cart templates to allow their styling.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $classname
	 * @param  array   $cart_item
	 * @return string
	 */
	public function mini_cart_item_class( $classname, $cart_item ) {

		if ( wc_pc_is_combined_cart_item( $cart_item ) ) {
			$classname .= ' combined_mini_cart_item';
		} elseif ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {
			$classname .= ' combo_container_mini_cart_item';
		}

		return $classname;
	}


	/**
	 * Only show combined items in the mini cart if their parent line item is hidden.
	 *
	 * @param  boolean  $show
	 * @param  array    $cart_item
	 * @param  string   $cart_item_key
	 * @return boolean
	 */
	public function cart_widget_item_visible( $show, $cart_item, $cart_item_key ) {

		if ( $container = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			$combo = $container[ 'data' ];

			if ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'parent_item' ) && WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'parent_cart_widget_item_meta' ) ) {
				$show = false;
			} elseif ( WC_Product_Combo::group_mode_has( $combo->get_group_mode(), 'component_multiselect' ) ) {
				$show = false;
			}
		}

		return $show;
	}

	/**
	 * Tweak combo container qty.
	 *
	 * @param  bool    $qty
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return bool
	 */
	public function cart_widget_item_qty( $qty, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			if ( WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'aggregated_subtotals' ) ) {

				if ( WC_LafkaCombos()->cart->container_cart_item_contains( $cart_item, 'sold_individually' ) ) {
					$qty = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $cart_item[ 'data' ], $cart_item[ 'quantity' ] ), $cart_item, $cart_item_key );
				}

			} elseif ( empty( $cart_item[ 'line_subtotal' ] ) && $cart_item[ 'data' ]->contains( 'priced_individually' ) ) {

				$combined_item_keys = wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents, true );

				if ( ! empty( $combined_item_keys ) ) {
					$qty = '';
				}
			}

		} elseif ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			if ( ! empty( $cart_item[ 'line_subtotal' ] ) ) {
				return $qty;
			}

			$combined_item_id = $cart_item[ 'combined_item_id' ];
			$combined_item    = $combo_container_item[ 'data' ]->get_combined_item( $cart_item[ 'combined_item_id' ] );

			if ( ! $combined_item ) {
				return $qty;
			}

			if ( ! $combined_item->is_priced_individually() && ! WC_Product_Combo::group_mode_has( $combo_container_item[ 'data' ]->get_group_mode(), 'parent_cart_widget_item_meta' ) ) {
				$qty = '';
			}
		}

		return $qty;
	}

	/**
	 * Tweak combo container name.
	 *
	 * @param  bool    $show
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return bool
	 */
	public function cart_widget_container_item_name( $name, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			if ( WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'aggregated_subtotals' ) ) {

				if ( WC_LafkaCombos()->cart->container_cart_item_contains( $cart_item, 'sold_individually' ) && ! WC_LafkaCombos()->compatibility->is_composited_cart_item( $cart_item ) ) {
					$name = WC_LafkaCombos_Helpers::format_product_shop_title( $name, $cart_item[ 'quantity' ] );
				}

			} elseif ( empty( $cart_item[ 'line_subtotal' ] ) && $cart_item[ 'data' ]->contains( 'priced_individually' ) ) {

				$combined_item_keys = wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents, true );

				if ( ! empty( $combined_item_keys ) ) {
					$name = WC_LafkaCombos_Helpers::format_product_shop_title( $name, $cart_item[ 'quantity' ] );
				}
			}

		} elseif ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

			if ( ! empty( $cart_item[ 'line_subtotal' ] ) ) {
				return $name;
			}

			$combined_item_id = $cart_item[ 'combined_item_id' ];
			$combined_item    = $combo_container_item[ 'data' ]->get_combined_item( $cart_item[ 'combined_item_id' ] );

			if ( ! $combined_item ) {
				return $name;
			}

			if ( ! $combined_item->is_priced_individually() && ! WC_Product_Combo::group_mode_has( $combo_container_item[ 'data' ]->get_group_mode(), 'parent_cart_widget_item_meta' ) ) {
				$name = WC_LafkaCombos_Helpers::format_product_shop_title( $name, $cart_item[ 'quantity' ] );
			}
		}

		return $name;
	}

	/**
	 * Gets combined content data.
	 *
	 * @since  5.8.0
	 *
	 * @param  array  $cart_item
	 * @return array
	 */
	public function get_combo_container_cart_item_data( $cart_item, $formatted = false ) {

		$data = array();

		$combined_cart_items = wc_pc_get_combined_cart_items( $cart_item );

		if ( ! empty( $combined_cart_items ) ) {

			$combined_item_descriptions = array();

			foreach ( $combined_cart_items as $combined_cart_item_key => $combined_cart_item ) {

				$combined_item_id          = $combined_cart_item[ 'combined_item_id' ];
				$combined_item_description = '';

				if ( $combined_item = $cart_item[ 'data' ]->get_combined_item( $combined_item_id ) ) {

					if ( $combined_item->is_visible( 'cart' ) ) {
						$combined_item_description = WC_LafkaCombos_Helpers::format_product_shop_title( $combined_cart_item[ 'data' ]->get_name(), $combined_cart_item[ 'quantity' ] );
					}

					/**
					 * 'woocommerce_combo_container_cart_item_data_value' filter.
					 *
					 * @since  5.8.0
					 *
					 * @param  string  $combined_item_description
					 * @param  array   $combined_cart_item
					 * @param  string  $combined_cart_item_key
					 */
					$combined_item_description = apply_filters( 'woocommerce_combo_container_cart_item_data_value', $combined_item_description, $combined_cart_item, $combined_cart_item_key );
				}

				if ( $combined_item_description ) {
					$combined_item_descriptions[] = $combined_item_description;
				}
			}

			if ( ! empty( $combined_item_descriptions ) ) {

				$data[] = array(
					'key'   => __( 'Includes', 'lafka-plugin' ),
					'value' => implode( '<br/>', $combined_item_descriptions )
				);
			}
		}

		if ( $formatted ) {

			$formatted_data = '';

			if ( ! empty( $data ) ) {

				ob_start();

				wc_get_template( 'cart/combo-container-item-data.php', array(
					'data' => $data
				), false, WC_LafkaCombos()->plugin_path() . '/templates/' );

				$formatted_data = ob_get_clean();
			}

			$data = $formatted_data;
		}

		return $data;
	}

	/**
	 * Adds content data as parent item meta (by default in the mini-cart only).
	 *
	 * @param  array  $data
	 * @param  array  $cart_item
	 * @return array
	 */
	public function cart_widget_container_item_data( $data, $cart_item ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {
			if ( WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'parent_cart_widget_item_meta' ) ) {
				$data = array_merge( $data, $this->get_combo_container_cart_item_data( $cart_item ) );
			}
		}

		return $data;
	}

	/*
	|--------------------------------------------------------------------------
	| Orders.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Modify the subtotal of order items depending on their pricing setup.
	 *
	 * @param  string         $subtotal
	 * @param  WC_Order_Item  $item
	 * @param  WC_Order       $order
	 * @return string
	 */
	public function order_item_subtotal( $subtotal, $item, $order ) {

		// If it's a combined item...
		if ( $combo_container_item = wc_pc_get_combined_order_item_container( $item, $order ) ) {

			$combined_item_priced_individually = $item->get_meta( '_combined_item_priced_individually', true );
			$combined_item_price_hidden        = $item->get_meta( '_combined_item_price_hidden', true );

			// Back-compat.
			if ( ! in_array( $combined_item_priced_individually, array( 'yes', 'no' ) ) ) {
				$combined_item_priced_individually = isset( $combo_container_item[ 'per_product_pricing' ] ) ? $combo_container_item[ 'per_product_pricing' ] : get_post_meta( $combo_container_item[ 'product_id' ], '_wc_pb_v4_per_product_pricing', true );
			}

			$is_pip = WC_LafkaCombos()->compatibility->is_pip( 'invoice' );

			if ( 'no' === $combined_item_priced_individually && $item->get_subtotal( 'edit' ) == 0 ) {

				$subtotal = '';

			} elseif ( ! $is_pip && 'yes' === $combined_item_price_hidden ) {

				$subtotal = '';

			} elseif ( ! $is_pip ) {

				$group_mode = $combo_container_item->get_meta( '_combo_group_mode', true );
				$group_mode = $group_mode ? $group_mode : 'parent';

				if ( WC_Product_Combo::group_mode_has( $group_mode, 'aggregated_subtotals' ) ) {

					if ( WC_LafkaCombos()->compatibility->is_composited_order_item( $combo_container_item, $order ) ) {
						$subtotal = '';
					} elseif ( $subtotal ) {
						$subtotal = '<span class="combined_table_item_subtotal">' . sprintf( _x( '%1$s: %2$s', 'combined product subtotal', 'lafka-plugin' ), __( 'Subtotal', 'lafka-plugin' ), $subtotal ) . '</span>';
					}

				} elseif ( $subtotal && function_exists( 'wc_cp_get_composited_order_item_container' ) && ( $composite_container_item = wc_cp_get_composited_order_item_container( $combo_container_item, $order ) ) ) {

					if ( apply_filters( 'woocommerce_add_composited_order_item_subtotals', true, $composite_container_item, $order ) ) {

						$show_subtotal = true;

						if ( $item->get_subtotal( 'edit' ) == 0 && 'yes' === $combined_item_priced_individually ) {
							if ( $component_priced_individually = $combo_container_item->get_meta( '_component_priced_individually', true ) ) {
								$show_subtotal = 'yes' === $component_priced_individually;
							}
						}

						if ( $show_subtotal ) {
							$subtotal = '<span class="combined_table_item_subtotal">' . sprintf( _x( '%1$s: %2$s', 'combined product subtotal', 'lafka-plugin' ), __( 'Subtotal', 'lafka-plugin' ), $subtotal ) . '</span>';
						} else {
							$subtotal = '';
						}
					}
				}
			}

		// If it's a combo (parent item)...
		} elseif ( wc_pc_is_combo_container_order_item( $item ) ) {

			if ( 'yes' !== $item->get_meta( '_lafka_child_subtotals_added' ) ) {

				$group_mode = $item->get_meta( '_combo_group_mode', true );
				$group_mode = $group_mode ? $group_mode : 'parent';

				$children            = wc_pc_get_combined_order_items( $item, $order );
				$aggregate_subtotals = WC_Product_Combo::group_mode_has( $group_mode, 'aggregated_subtotals' ) && false === WC_LafkaCombos()->compatibility->is_pip( 'invoice' );

				// Aggregate subtotals if required the combo's group mode. Important: Don't aggregate when rendering PIP invoices!
				if ( $aggregate_subtotals ) {

					if ( ! empty( $children ) ) {

						// Create a clone to ensure the original item will not be modified.
						$cloned_item = clone $item;

						foreach ( $children as $child ) {
							$cloned_item->set_subtotal( $cloned_item->get_subtotal( 'edit' ) + round( $child->get_subtotal( 'edit' ), wc_pc_price_num_decimals() ) );
							$cloned_item->set_subtotal_tax( $cloned_item->get_subtotal_tax( 'edit' ) + round( $child->get_subtotal_tax( 'edit' ), wc_pc_price_num_decimals() ) );
						}

						$cloned_item->add_meta_data( '_lafka_child_subtotals_added', 'yes' );

						$subtotal = $order->get_formatted_line_subtotal( $cloned_item );
					}

				} elseif ( sizeof( $children ) && $item->get_subtotal( 'edit' ) == 0 ) {
					$subtotal = '';
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Visibility of combined item in orders.
	 *
	 * @param  boolean  $visible
	 * @param  array    order_item
	 * @return boolean
	 */
	public function order_item_visible( $visible, $order_item ) {

		if ( wc_pc_maybe_is_combined_order_item( $order_item ) ) {

			$combined_item_hidden = $order_item->get_meta( '_combined_item_hidden' );

			if ( ! empty( $combined_item_hidden ) ) {
				$visible = false;
			}

		} elseif ( wc_pc_is_combo_container_order_item( $order_item ) ) {

			$group_mode = $order_item->get_meta( '_combo_group_mode', true );
			$group_mode = $group_mode ? $group_mode : 'parent';

			if ( false === WC_Product_Combo::group_mode_has( $group_mode, 'parent_item' ) ) {
				$visible = false;
			}
		}

		return $visible;
	}

	/**
	 * Override combined item title in order-details template.
	 *
	 * @param  string  $content
	 * @param  array   $order_item
	 * @return string
	 */
	public function order_item_title( $content, $order_item ) {

		if ( false !== $this->order_item_order && wc_pc_is_combined_order_item( $order_item, $this->order_item_order ) ) {

			$this->order_item_order = false;

			$group_mode = $order_item->get_meta( '_combo_group_mode', true );
			$group_mode = $group_mode ? $group_mode : 'parent';

			if ( WC_Product_Combo::group_mode_has( $group_mode, 'child_item_indent' ) ) {
				if ( did_action( 'woocommerce_view_order' ) || did_action( 'woocommerce_thankyou' ) || did_action( 'before_woocommerce_pay' ) || did_action( 'woocommerce_account_view-subscription_endpoint' ) ) {
					$this->enqueue_combined_table_item_js();
				}
			}
		}

		return $content;
	}

	/**
	 * Add class to combined items in order templates.
	 *
	 * @param  string  $classname
	 * @param  array   $order_item
	 * @return string
	 */
	public function order_item_class( $classname, $order_item, $order ) {

		if ( $combo_container_order_item = wc_pc_get_combined_order_item_container( $order_item, $order ) ) {

			$group_mode = $combo_container_order_item->get_meta( '_combo_group_mode', true );
			$group_mode = $group_mode ? $group_mode : 'parent';

			if ( WC_Product_Combo::group_mode_has( $group_mode, 'child_item_indent' ) ) {

				if ( WC_Product_Combo::group_mode_has( $group_mode, 'faked_parent_item' ) ) {

					// Ensure this isn't the first child.
					$combined_order_item_ids = wc_pc_get_combined_order_items( $combo_container_order_item, $order, true );

					if ( empty( $combined_order_item_ids ) || current( $combined_order_item_ids ) !== $order_item->get_id() ) {
						$classname .= ' combined_table_item';
					}

				} else {
					$classname .= ' combined_table_item';
				}
			}

			$this->order_item_order = $order;

		} elseif ( wc_pc_is_combo_container_order_item( $order_item ) ) {
			$classname .= ' combo_table_item';
		}

		return $classname;
	}

	/**
	 * Filters the reported number of order items.
	 *
	 * @param  int       $count
	 * @param  string    $type
	 * @param  WC_Order  $order
	 * @return int
	 */
	public function order_item_count( $count, $type, $order ) {

		$subtract = 0;

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {

			foreach ( $order->get_items() as $item ) {
				if ( wc_pc_is_combo_container_order_item( $item, $order ) ) {

					$parent_item_visible = $this->order_item_visible( true, $item );

					if ( ! $parent_item_visible ) {
						$subtract += $item->get_quantity();
					}


					$combined_order_items = wc_pc_get_combined_order_items( $item, $order );

					foreach ( $combined_order_items as $combined_item_key => $combined_order_item ) {
						if ( ! $parent_item_visible ) {
							if ( ! $this->order_item_visible( true, $combined_order_item ) ) {
								$subtract += $combined_order_item->get_quantity();
							}
						} else {
							$subtract += $combined_order_item->get_quantity();
						}
					}
				}
			}
		}

		return $count - $subtract;
	}

	/**
	 * Indent combined items in emails.
	 *
	 * @param  string  $css
	 * @return string
	 */
	public function email_styles( $css ) {
		$css .= ' .combined_table_item td:first-of-type { padding-left: 2.5em !important; } .combined_table_item td { border-top: none; font-size: 0.875em; } #body_content table tr.combined_table_item td ul.wc-item-meta { font-size: inherit; } ';
		return $css;
	}

	/*
	|--------------------------------------------------------------------------
	| Archives.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Used to fix QuickView support when:
	 * - ajax add-to-cart is active and
	 * - QuickView operates without a separate button.
	 * Since WC 2.5+ this is (almost) a relic.
	 *
	 * @param  string      $link
	 * @param  WC_Product  $product
	 * @return string
	 */
	public function loop_add_to_cart_link( $link, $product ) {

		if ( $product->is_type( 'combo' ) ) {

			if ( ! $product->is_in_stock() || $product->has_options() ) {
				$link = str_replace( array( 'product_type_combo', 'ajax_add_to_cart' ), array( 'product_type_combo product_type_combo_input_required', '' ), $link );
			}
		}

		return $link;
	}

	/*
	|--------------------------------------------------------------------------
	| Other.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Enhance price filter widget meta query to include results based on max '_wc_sw_max_price' meta.
	 *
	 * @param  array     $meta_query
	 * @param  WC_Query  $wc_query
	 * @return array
	 */
	public function price_filter_query_params( $meta_query, $wc_query ) {

		if ( isset( $meta_query[ 'price_filter' ] ) && isset( $meta_query[ 'price_filter' ][ 'price_filter' ] ) && ! isset( $meta_query[ 'price_filter' ][ 'sw_price_filter' ] ) ) {

			$min = isset( $_GET[ 'min_price' ] ) ? floatval( $_GET[ 'min_price' ] ) : 0;
			$max = isset( $_GET[ 'max_price' ] ) ? floatval( $_GET[ 'max_price' ] ) : 9999999999;

			$price_meta_query = $meta_query[ 'price_filter' ];
			$price_meta_query = array(
				'sw_price_filter' => true,
				'price_filter'    => true,
				'relation'        => 'OR',
				$price_meta_query,
				array(
					'relation' => 'AND',
					array(
						'key'     => '_price',
						'compare' => '<=',
						'type'    => 'DECIMAL',
						'value'   => $max
					),
					array(
						'key'     => '_wc_sw_max_price',
						'compare' => '>=',
						'type'    => 'DECIMAL',
						'value'   => $min
					)
				)
			);

			$meta_query[ 'price_filter' ] = $price_meta_query;
		}

		return $meta_query;
	}

	/*
	|--------------------------------------------------------------------------
	| Deprecated.
	|--------------------------------------------------------------------------
	*/

	public function order_table_item_title( $content, $order_item ) {
		_deprecated_function( __METHOD__ . '()', '5.5.0', __CLASS__ . '::order_item_title()' );
		return $this->order_item_title( $content, $order_item );
	}
	public function woo_combos_loop_add_to_cart_link( $link, $product ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::loop_add_to_cart_link()' );
		return $this->loop_add_to_cart_link( $link, $product );
	}
	public function woo_combos_in_cart_item_title( $content, $cart_item_values, $cart_item_key ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::cart_item_title()' );
		return $this->cart_item_title( $content, $cart_item_values, $cart_item_key );
	}
	public function woo_combos_order_table_item_title( $content, $order_item ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::order_item_title()' );
		return $this->order_item_title( $content, $order_item );
	}
	public function woo_combos_table_item_class( $classname, $values ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::table_item_class()' );
		return false !== strpos( $classname, 'cart_item' ) ? $this->cart_item_class( $classname, $values, false ) : $this->order_item_class( $classname, $values, false );
	}
	public function woo_combos_frontend_scripts() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::frontend_scripts()' );
		return $this->frontend_scripts();
	}
	public function woo_combos_cart_contents_count( $count ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::cart_contents_count()' );
		return $this->cart_contents_count( $count );
	}
	public function woo_combos_add_cart_widget_filters() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::add_cart_widget_filters()' );
		return $this->add_cart_widget_filters();
	}
	public function woo_combos_remove_cart_widget_filters() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::remove_cart_widget_filters()' );
		return $this->remove_cart_widget_filters();
	}
	public function woo_combos_order_item_visible( $visible, $order_item ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::order_item_visible()' );
		return $this->order_item_visible( $visible, $order_item );
	}
	public function woo_combos_cart_item_visible( $visible, $cart_item, $cart_item_key ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::cart_item_visible()' );
		return $this->cart_item_visible( $visible, $cart_item, $cart_item_key );
	}
	public function woo_combos_email_styles( $css ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::email_styles()' );
		return $this->email_styles( $css );
	}
}
