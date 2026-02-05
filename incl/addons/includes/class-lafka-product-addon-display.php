<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lafka_Product_Addon_Display class.
 */
class Lafka_Product_Addon_Display {

	/**
	 * Initialize frontend actions.
	 */
	public function __construct() {
		// Styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'addon_scripts' ) );

		// Addon display.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display' ), 10 );
		add_action( 'woocommerce_before_variations_form', array( $this, 'reposition_display_for_variable_product' ), 10 );
		add_action( 'lafka-product-addons_end', array( $this, 'totals' ), 10 );

		// Change buttons/cart urls.
		add_filter( 'add_to_cart_text', array( $this, 'add_to_cart_text' ), 15 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'add_to_cart_text' ), 15, 2 );
		add_filter( 'woocommerce_add_to_cart_url', array( $this, 'add_to_cart_url' ), 10, 1 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'add_to_cart_url' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'ajax_add_to_cart_supports' ), 10, 3 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'prevent_purchase_at_grouped_level' ), 10, 2 );

		// View order.
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'fix_file_uploaded_display' ) );
	}

	/**
	 * Enqueue add-ons scripts.
	 */
	public function addon_scripts() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'accounting', WC()->plugin_url() . '/assets/js/accounting/accounting' . $suffix . '.js', array( 'jquery' ), '0.4.2' );
		wp_enqueue_script( 'lafka-addons', plugins_url( '../assets/js/addons' . $suffix . '.js', __FILE__ ), array( 'jquery', 'accounting' ), '1.0', true );

		$params = array(
			'price_display_suffix'         => esc_attr( get_option( 'woocommerce_price_display_suffix' ) ),
			'ajax_url'                     => WC()->ajax_url(),
			'i18n_addon_total'             => esc_html__( 'Options total:', 'lafka-plugin' ),
			'i18n_sub_total'               => esc_html__( 'Sub total:', 'lafka-plugin' ),
			'i18n_remaining'               => esc_html__( 'characters remaining', 'lafka-plugin' ),
			'currency_format_num_decimals' => absint( get_option( 'woocommerce_price_num_decimals' ) ),
			'currency_format_symbol'       => get_woocommerce_currency_symbol(),
			'currency_format_decimal_sep'  => esc_attr( stripslashes( get_option( 'woocommerce_price_decimal_sep' ) ) ),
			'currency_format_thousand_sep' => esc_attr( stripslashes( get_option( 'woocommerce_price_thousand_sep' ) ) ),
		);

		if ( ! function_exists( 'get_woocommerce_price_format' ) ) {
			$currency_pos = get_option( 'woocommerce_currency_pos' );

			switch ( $currency_pos ) {
				case 'left' :
					$format = '%1$s%2$s';
					break;
				case 'right' :
					$format = '%2$s%1$s';
					break;
				case 'left_space' :
					$format = '%1$s&nbsp;%2$s';
					break;
				case 'right_space' :
					$format = '%2$s&nbsp;%1$s';
					break;
			}

			$params['currency_format'] = esc_attr( str_replace( array( '%1$s', '%2$s' ), array( '%s', '%v' ), $format ) );
		} else {
			$params['currency_format'] = esc_attr( str_replace( array( '%1$s', '%2$s' ), array( '%s', '%v' ), get_woocommerce_price_format() ) );
		}

		wp_localize_script( 'lafka-addons', 'lafka_addons_params', $params );
	}

	/**
	 * Get the plugin path.
	 */
	public function plugin_path() {
		return $this->plugin_path = untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) );
	}

	/**
	 * Display add-ons.
	 *
	 * @param int|bool    $post_id Post ID (default: false).
	 * @param string|bool $prefix  Add-on prefix.
	 */
	public function display( $post_id = false, $prefix = false ) {
		global $product;

		if ( ! $post_id ) {
			global $post;
			$post_id = $post->ID;
		}

		$this->addon_scripts();

		$product_addons = WC_Product_Addons_Helper::get_product_addons( $post_id, $prefix );

		if ( is_array( $product_addons ) && count( $product_addons ) > 0 ) {
			do_action( 'lafka-product-addons_start', $post_id );

			foreach ( $product_addons as $addon ) {
				if ( ! isset( $addon['field-name'] ) ) {
					continue;
				}

				$has_options_with_images = false;
				if ( is_array( $addon['options'] ) ) {
					foreach ( $addon['options'] as $option ) {
						if ( ! empty( $option['image'] ) ) {
							$has_options_with_images = true;
						}
					}
				}

				wc_get_template( 'addon-start.php', array(
					'addon'       => $addon,
					'required'    => $addon['required'],
					'name'        => $addon['name'],
					'description' => $addon['description'],
					'type'        => $addon['type'],
					'has_options_with_images' => $has_options_with_images,
				), 'lafka-plugin', $this->plugin_path() . '/templates/' );

				echo $this->get_addon_html( $addon );

				wc_get_template( 'addon-end.php', array(
					'addon' => $addon,
				), 'lafka-plugin', $this->plugin_path() . '/templates/' );
			}

			do_action( 'lafka-product-addons_end', $post_id );
		}
	}

	/**
	 * Update totals to include prduct add-ons.
	 *
	 * @param int $post_id Post ID.
	 */
	public function totals( $post_id ) {
		global $product;

		if ( ! isset( $product ) || $product->get_id() != $post_id ) {
			$the_product = wc_get_product( $post_id );
		} else {
			$the_product = $product;
		}

		if ( is_object( $the_product ) ) {
			$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );
			$display_price    = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $the_product ) : wc_get_price_excluding_tax( $the_product );
		} else {
			$display_price = '';
			$raw_price     = 0;
		}

		if ( 'no' === get_option( 'woocommerce_prices_include_tax' ) ) {
			$tax_mode  = 'excl';
			$raw_price = wc_get_price_excluding_tax( $the_product );
		} else {
			$tax_mode  = 'incl';
			$raw_price = wc_get_price_including_tax( $the_product );
		}

		echo '<div id="product-addons-total" data-show-sub-total="' . ( apply_filters( 'lafka_product_addons_show_grand_total', true, $the_product ) ? 1 : 0 ) . '" data-type="' . esc_attr( $the_product->get_type() ) . '" data-tax-mode="' . esc_attr( $tax_mode ) . '" data-tax-display-mode="' . esc_attr( $tax_display_mode ) . '" data-price="' . esc_attr( $display_price ) . '" data-raw-price="' . esc_attr( $raw_price ) . '" data-product-id="' . esc_attr( $post_id ) . '"></div>';
	}

	/**
	 * Get add-on field HTML.
	 *
	 * @param array $addon Add-on field data.
	 * @return string
	 */
	public function get_addon_html( $addon ) {
		ob_start();

		$method_name = 'get_' . $addon['type'] . '_html';

		if ( method_exists( $this, $method_name ) ) {
			$this->$method_name( $addon );
		}

		do_action( 'lafka-product-addons_get_' . $addon['type'] . '_html', $addon );

		return ob_get_clean();
	}

	/**
	 * Get checkbox field HTML.
	 *
	 * @param array $addon Add-on field data.
	 */
	public function get_checkbox_html( $addon ) {
		wc_get_template( 'checkbox.php', array(
			'addon' => $addon,
		), 'lafka-plugin', $this->plugin_path() . '/templates/' );
	}

	/**
	 * Get radio button field HTML.
	 *
	 * @param array $addon Add-on field data.
	 */
	public function get_radiobutton_html( $addon ) {
		wc_get_template( 'radiobutton.php', array(
			'addon' => $addon,
		), 'lafka-plugin', $this->plugin_path() . '/templates/' );
	}

	/**
	 * Get custom textarea field HTML.
	 *
	 * @param array $addon Add-on field data.
	 */
	public function get_textarea_html( $addon ) {
		wc_get_template( 'textarea.php', array(
			'addon' => $addon,
		), 'lafka-plugin', $this->plugin_path() . '/templates/' );
	}

	/**
	 * Check required add-ons.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	protected function check_required_addons( $product_id ) {
		// No parent add-ons, but yes to global.
		$addons = WC_Product_Addons_Helper::get_product_addons( $product_id, false, false, true );

		if ( $addons && ! empty( $addons ) ) {
			foreach ( $addons as $addon ) {
				if ( '1' == $addon['required'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Add to cart text.
	 *
	 * @since 1.0.0
	 * @version 2.9.0
	 * @param string $text Add to cart text.
	 * @param object $product
	 * @return string
	 */
	public function add_to_cart_text( $text, $product = null ) {
		if ( null === $product ) {
			global $product;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $text;
		}

		if ( ! is_single( $product->get_id() ) ) {
			if ( $this->check_required_addons( $product->get_id() ) ) {
				$text = apply_filters( 'addons_add_to_cart_text', esc_html__( 'Select options', 'lafka-plugin' ) );
			}
		}

		return $text;
	}

	/**
	 * Removes ajax-add-to-cart functionality in WC 2.5 when a product has required add-ons.
	 *
	 * @param  bool       $supports If support a feature.
	 * @param  string     $feature  Feature to support.
	 * @param  WC_Product $product  Product data.
	 * @return bool
	 */
	public function ajax_add_to_cart_supports( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $this->check_required_addons( $product->get_id() ) ) {
			$supports = false;
		}

		return $supports;
	}

	/**
	 * Include product add-ons to add to cart URL.
	 *
	 * @since 1.0.0
	 * @version 2.9.0
	 * @param string $url Add to cart URL.
	 * @param object $product
	 * @return string
	 */
	public function add_to_cart_url( $url, $product = null ) {
		if ( null === $product ) {
			global $product;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $url;
		}

		if ( ! is_single( $product->get_id() ) && in_array( $product->get_type(), apply_filters( 'lafka_product_addons_add_to_cart_product_types', array( 'subscription', 'simple' ) ) ) && ( ! isset( $_GET['wc-api'] ) || 'WC_Quick_View' !== $_GET['wc-api'] ) ) {
			if ( $this->check_required_addons( $product->get_id() ) ) {
				$url = apply_filters( 'addons_add_to_cart_url', get_permalink( $product->get_id() ) );
			}
		}

		return $url;
	}

	/**
	 * Don't let products with required addons be added to cart when viewing grouped products.
	 *
	 * @param  bool       $purchasable If product is purchasable.
	 * @param  WC_Product $product     Product data.
	 * @return bool
	 */
	public function prevent_purchase_at_grouped_level( $purchasable, $product ) {
		if ( version_compare( WC_VERSION, '3.0.0', '<' ) ) {
			$product_id = $product->parent->id;
		} else {
			$product_id = $product->get_parent_id();
		}

		if ( $product && ! $product->is_type( 'variation' ) && $product_id && is_single( $product_id ) && $this->check_required_addons( $product->get_id() ) ) {
			$purchasable = false;
		}
		return $purchasable;
	}

	/**
	 * Fix the display of uploaded files.
	 *
	 * @param  string $meta_value Meta value.
	 * @return string
	 */
	public function fix_file_uploaded_display( $meta_value ) {
		global $wp;

		// If the value is a string, is a URL to an uploaded file, and we're not in the WC API, reformat this string as an anchor tag.
		if ( is_string( $meta_value ) && ! isset( $wp->query_vars['wc-api'] ) && false !== strpos( $meta_value, '/product_addons_uploads/' ) ) {
			$file_url   = $meta_value;
			$meta_value = basename( $meta_value );
			$meta_value = '<a href="' . esc_url( $file_url ) . '">' . esc_html( $meta_value ) . '</a>';
		}

		return $meta_value;
	}

	/**
	 * Fix product addons position on variable products - show them after a single variation description
	 * or out of stock message.
	 */
	public function reposition_display_for_variable_product() {
		remove_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display' ), 10 );
		add_action( 'woocommerce_single_variation', array( $this, 'display' ), 15 );
	}

	/**
	 * Get id of the option custom image
	 *
	 * @param $option
	 *
	 * @return string
	 */
	public function get_addon_option_custom_image_id( $option ) : string {
		$custom_image_id = '';
		if ( !empty( $option['image'] ) ) {
			$custom_image_id = $option['image'];
		}

		return $custom_image_id;
	}

	/**
	 * Return the classes for the option image tag
	 *
	 * @param $custom_image_id
	 *
	 * @return array|string[]
	 */
	public function get_addon_option_image_classes( $custom_image_id ): array {
		$custom_image_classes = array();

		if ( $custom_image_id ) {
			$custom_image_url     = wp_get_attachment_image_url( $custom_image_id );
			$custom_image_classes = array( 'lafka-addon-image-icon' );
			if ( substr( $custom_image_url, - 4 ) === '.svg' ) {
				$custom_image_classes[] = 'lafka-svg-icon';
			}
		}

		return $custom_image_classes;
	}
}
