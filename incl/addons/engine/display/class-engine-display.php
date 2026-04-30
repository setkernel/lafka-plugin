<?php
/**
 * Lafka_Engine_Display — front-end addon rendering on the product page.
 *
 * Owns:
 *   - addon JS enqueue (accounting.js + addons.js + localized params)
 *   - PDP form render via two scoped callbacks (simple/grouped vs variable),
 *     each early-returning when the other type is in scope so they stay
 *     correctly partitioned without ever mutating the global hook table
 *   - "Select options" overrides on the add-to-cart button when a product
 *     has required addons (text + URL + AJAX-disable + grouped-purchase-block)
 *   - file upload display formatting on order line items
 *
 * Replaces Lafka_Product_Addon_Display. Wire-level behavior preserved —
 * same hooks, same priorities, same templates. The only changes are the
 * namespace and the use of Lafka_Engine_Helper::get_product_addons() for
 * the central read.
 *
 * Theme-overridable templates remain at incl/addons/templates/ so existing
 * theme/plugin overrides keep working without rebasing.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Display {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Addon display — two scoped callbacks, one for simple/grouped/etc.
		// at standard `before_add_to_cart_button`, one for variable at
		// `single_variation` (priority 15, between WC's two stock callbacks
		// at 10 and 20). Each callback early-returns if the other product
		// type is in scope, so they stay correctly partitioned without ever
		// mutating the global hook table.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display_for_simple_product' ), 10 );
		add_action( 'woocommerce_single_variation', array( $this, 'display_for_variable_product' ), 15 );
		add_action( 'lafka-product-addons_end', array( $this, 'totals' ), 10 );

		// Add-to-cart button overrides for products with required addons.
		add_filter( 'add_to_cart_text', array( $this, 'add_to_cart_text' ), 15 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'add_to_cart_text' ), 15, 2 );
		add_filter( 'woocommerce_add_to_cart_url', array( $this, 'add_to_cart_url' ), 10 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'add_to_cart_url' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'ajax_add_to_cart_supports' ), 10, 3 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'prevent_purchase_at_grouped_level' ), 10, 2 );

		// Order view: turn file-upload URLs into clickable links.
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'fix_file_uploaded_display' ) );
	}

	public function enqueue_scripts(): void {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script(
			'accounting',
			WC()->plugin_url() . '/assets/js/accounting/accounting' . $suffix . '.js',
			array( 'jquery' ),
			'0.4.2'
		);

		$addons_rel = 'incl/addons/assets/js/addons' . $suffix . '.js';
		wp_enqueue_script(
			'lafka-addons',
			plugins_url( '../assets/js/addons' . $suffix . '.js', LAFKA_ADDONS_ENGINE_PATH . '/.' ),
			array( 'jquery', 'accounting' ),
			function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $addons_rel ) : '8.15.0',
			true
		);

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
		$params['currency_format'] = esc_attr(
			str_replace( array( '%1$s', '%2$s' ), array( '%s', '%v' ), get_woocommerce_price_format() )
		);

		wp_localize_script( 'lafka-addons', 'lafka_addons_params', $params );
	}

	/**
	 * Path used by wc_get_template() lookups. Templates live one level above
	 * the engine root in incl/addons/templates/ so existing theme overrides
	 * (which target the lafka-plugin/templates/ slug) keep working.
	 */
	public function plugin_path(): string {
		return untrailingslashit( dirname( LAFKA_ADDONS_ENGINE_PATH ) );
	}

	public function display_for_simple_product( $post_id = false, $prefix = false ): void {
		global $product;
		if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
			return;
		}
		$this->display( $post_id, $prefix );
	}

	public function display_for_variable_product( $post_id = false, $prefix = false ): void {
		global $product;
		if ( $product instanceof WC_Product && ! $product->is_type( 'variable' ) ) {
			return;
		}
		$this->display( $post_id, $prefix );
	}

	/**
	 * @param int|bool    $post_id
	 * @param string|bool $prefix
	 */
	public function display( $post_id = false, $prefix = false ): void {
		if ( ! $post_id ) {
			global $post;
			$post_id = $post->ID ?? 0;
		}

		$this->enqueue_scripts();

		$product_addons = Lafka_Engine_Helper::get_product_addons( $post_id, $prefix );
		if ( ! is_array( $product_addons ) || empty( $product_addons ) ) {
			return;
		}

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
						break;
					}
				}
			}

			wc_get_template(
				'addon-start.php',
				array(
					'addon'                   => $addon,
					'required'                => $addon['required'],
					'name'                    => $addon['name'],
					'description'             => $addon['description'],
					'type'                    => $addon['type'],
					'has_options_with_images' => $has_options_with_images,
				),
				'lafka-plugin',
				$this->plugin_path() . '/templates/'
			);

			echo $this->get_addon_html( $addon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			wc_get_template(
				'addon-end.php',
				array( 'addon' => $addon ),
				'lafka-plugin',
				$this->plugin_path() . '/templates/'
			);
		}

		do_action( 'lafka-product-addons_end', $post_id );
	}

	public function totals( $post_id ): void {
		global $product;

		$the_product = ( isset( $product ) && (int) $product->get_id() === (int) $post_id ) ? $product : wc_get_product( $post_id );
		if ( ! is_object( $the_product ) ) {
			return;
		}

		$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );
		$display_price    = 'incl' === $tax_display_mode
			? wc_get_price_including_tax( $the_product )
			: wc_get_price_excluding_tax( $the_product );

		if ( 'no' === get_option( 'woocommerce_prices_include_tax' ) ) {
			$tax_mode  = 'excl';
			$raw_price = wc_get_price_excluding_tax( $the_product );
		} else {
			$tax_mode  = 'incl';
			$raw_price = wc_get_price_including_tax( $the_product );
		}

		printf(
			'<div id="product-addons-total" data-show-sub-total="%d" data-type="%s" data-tax-mode="%s" data-tax-display-mode="%s" data-price="%s" data-raw-price="%s" data-product-id="%s"></div>',
			(int) apply_filters( 'lafka_product_addons_show_grand_total', true, $the_product ),
			esc_attr( $the_product->get_type() ),
			esc_attr( $tax_mode ),
			esc_attr( $tax_display_mode ),
			esc_attr( (string) $display_price ),
			esc_attr( (string) $raw_price ),
			esc_attr( (string) $post_id )
		);
	}

	public function get_addon_html( array $addon ): string {
		ob_start();
		$method_name = 'get_' . $addon['type'] . '_html';
		if ( method_exists( $this, $method_name ) ) {
			$this->{$method_name}( $addon );
		}
		do_action( 'lafka-product-addons_get_' . $addon['type'] . '_html', $addon );
		return (string) ob_get_clean();
	}

	public function get_checkbox_html( array $addon ): void {
		wc_get_template( 'checkbox.php', array( 'addon' => $addon ), 'lafka-plugin', $this->plugin_path() . '/templates/' );
	}

	public function get_radiobutton_html( array $addon ): void {
		wc_get_template( 'radiobutton.php', array( 'addon' => $addon ), 'lafka-plugin', $this->plugin_path() . '/templates/' );
	}

	public function get_textarea_html( array $addon ): void {
		wc_get_template( 'textarea.php', array( 'addon' => $addon ), 'lafka-plugin', $this->plugin_path() . '/templates/' );
	}

	/**
	 * Static cache to avoid running the addon read 4× per archive page where
	 * each card calls into add_to_cart_text/url/supports/is_purchasable.
	 */
	protected function check_required_addons( int $product_id ): bool {
		static $cache = array();
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}

		// No parent add-ons, but yes to global.
		$addons = Lafka_Engine_Helper::get_product_addons( $product_id, false, false, true );

		$result = false;
		if ( ! empty( $addons ) ) {
			foreach ( $addons as $addon ) {
				if ( '1' === ( $addon['required'] ?? '' ) ) {
					$result = true;
					break;
				}
			}
		}

		$cache[ $product_id ] = $result;
		return $result;
	}

	public function add_to_cart_text( $text, $product = null ) {
		if ( null === $product ) {
			global $product;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $text;
		}

		if ( ! is_single( $product->get_id() ) && $this->check_required_addons( $product->get_id() ) ) {
			$text = (string) apply_filters( 'addons_add_to_cart_text', esc_html__( 'Select options', 'lafka-plugin' ) );
		}
		return $text;
	}

	/**
	 * Disable AJAX add-to-cart on shop archives for products with required addons.
	 */
	public function ajax_add_to_cart_supports( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $this->check_required_addons( $product->get_id() ) ) {
			return false;
		}
		return $supports;
	}

	public function add_to_cart_url( $url, $product = null ) {
		if ( null === $product ) {
			global $product;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $url;
		}

		$is_quick_view = isset( $_GET['wc-api'] ) && 'WC_Quick_View' === sanitize_text_field( wp_unslash( $_GET['wc-api'] ) );
		$applicable    = ! is_single( $product->get_id() )
			&& in_array( $product->get_type(), (array) apply_filters( 'lafka_product_addons_add_to_cart_product_types', array( 'subscription', 'simple' ) ), true )
			&& ! $is_quick_view;

		if ( $applicable && $this->check_required_addons( $product->get_id() ) ) {
			$url = (string) apply_filters( 'addons_add_to_cart_url', get_permalink( $product->get_id() ) );
		}
		return $url;
	}

	/**
	 * Block grouped-product purchase when the variation has required addons —
	 * customer must visit the variation's PDP to fill them in.
	 */
	public function prevent_purchase_at_grouped_level( $purchasable, $product ): bool {
		$parent_id = $product->get_parent_id();
		if ( $product && ! $product->is_type( 'variation' ) && $parent_id && is_single( $parent_id ) && $this->check_required_addons( $product->get_id() ) ) {
			return false;
		}
		return (bool) $purchasable;
	}

	/**
	 * Render uploaded-file order item meta as a clickable link.
	 */
	public function fix_file_uploaded_display( $meta_value ) {
		global $wp;
		if ( ! is_string( $meta_value ) ) {
			return $meta_value;
		}
		if ( isset( $wp->query_vars['wc-api'] ) ) {
			return $meta_value;
		}
		if ( false === strpos( $meta_value, '/product_addons_uploads/' ) ) {
			return $meta_value;
		}
		$file_url = $meta_value;
		return '<a href="' . esc_url( $file_url ) . '">' . esc_html( basename( $meta_value ) ) . '</a>';
	}

	/**
	 * Image utility helpers used by templates via the global instance.
	 */
	public function get_addon_option_custom_image_id( array $option ): string {
		return ! empty( $option['image'] ) ? (string) $option['image'] : '';
	}

	/**
	 * @return string[]
	 */
	public function get_addon_option_image_classes( $custom_image_id ): array {
		if ( ! $custom_image_id ) {
			return array();
		}
		$classes  = array( 'lafka-addon-image-icon' );
		$image_url = wp_get_attachment_image_url( $custom_image_id );
		if ( is_string( $image_url ) && '.svg' === substr( $image_url, -4 ) ) {
			$classes[] = 'lafka-svg-icon';
		}
		return $classes;
	}
}
