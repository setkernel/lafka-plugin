<?php
/**
 * Cart-drawer quantity stepper endpoint + client (GX4).
 *
 *   POST /?wc-ajax=lafka_cart_set_qty
 *     cart_item_key  a line in the visitor's own cart
 *     quantity       whole number >= 0 (0 removes the line)
 *     nonce          `lafka-cart-qty` (carried by each stepper row)
 *   → WooCommerce's refreshed fragments ({ fragments, cart_hash }), so the
 *     drawer rows, total and upsell re-render through the same callables as
 *     every other cart change. Errors: { success:false, data:{ code, message } }.
 *
 * Only live while the theme opts in (add_theme_support( 'lafka-drawer-stepper' )
 * or the `lafka_cart_drawer_stepper_enabled` filter); otherwise the endpoint
 * answers 404 and no script loads. WooCommerce's quantity rules hold: sold
 * individually, the purchase limit (stock without backorders), and
 * `woocommerce_update_cart_validation`.
 *
 * Page caching: the script's config holds no per-visitor value. The nonce
 * sits on each stepper row, which WooCommerce's fragment refresh re-renders;
 * on an expired nonce the client refreshes the fragments once and retries.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_cart_drawer_qty_error' ) ) {
	/**
	 * Answer an error and stop.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Customer-facing message.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	function lafka_cart_drawer_qty_error( string $code, string $message, int $status ) {
		wp_send_json_error(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}

if ( ! function_exists( 'lafka_cart_drawer_set_qty' ) ) {
	/**
	 * wc-ajax=lafka_cart_set_qty.
	 *
	 * @return void
	 */
	function lafka_cart_drawer_set_qty() {
		if ( ! function_exists( 'lafka_cart_drawer_stepper_enabled' ) || ! lafka_cart_drawer_stepper_enabled() ) {
			lafka_cart_drawer_qty_error( 'stepper_disabled', __( 'Not available.', 'lafka-plugin' ), 404 );
			return;
		}
		if ( false === check_ajax_referer( 'lafka-cart-qty', 'nonce', false ) ) {
			lafka_cart_drawer_qty_error( 'invalid_nonce', __( 'Your session has expired. Please try again.', 'lafka-plugin' ), 403 );
			return;
		}

		$key = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cart_item_key'] ) ) : '';
		$raw = isset( $_POST['quantity'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['quantity'] ) ) ) : '';

		$cart = function_exists( 'WC' ) && WC() && isset( WC()->cart ) ? WC()->cart : null;
		$item = ( null !== $cart && '' !== $key ) ? $cart->get_cart_item( $key ) : array();
		if ( empty( $item ) || ! is_array( $item ) ) {
			lafka_cart_drawer_qty_error( 'unknown_item', __( 'That item is no longer in your order.', 'lafka-plugin' ), 404 );
			return;
		}
		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			lafka_cart_drawer_qty_error( 'invalid_quantity', __( 'Please choose a quantity.', 'lafka-plugin' ), 400 );
			return;
		}
		$qty     = (int) $raw;
		$product = $item['data'] ?? null;

		if ( $qty > 0 ) {
			if ( $qty > 1 && is_object( $product ) && method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
				lafka_cart_drawer_qty_error( 'sold_individually', __( 'You can only have one of this item in your order.', 'lafka-plugin' ), 400 );
				return;
			}
			$max = is_object( $product ) && method_exists( $product, 'get_max_purchase_quantity' ) ? (int) $product->get_max_purchase_quantity() : -1;
			if ( $max > 0 && $qty > $max ) {
				lafka_cart_drawer_qty_error(
					'not_enough_stock',
					/* translators: %d: how many are available */
					sprintf( _n( 'Only %d is available.', 'Only %d are available.', $max, 'lafka-plugin' ), $max ),
					400
				);
				return;
			}
			// WooCommerce's own cart-page rule, so plugins that limit quantities hold here too.
			if ( ! apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
				$message = lafka_cart_drawer_qty_take_error_notice();
				lafka_cart_drawer_qty_error( 'invalid_quantity', '' !== $message ? $message : __( 'That quantity is not available.', 'lafka-plugin' ), 400 );
				return;
			}
		}

		$cart->set_quantity( $key, $qty, true );

		WC_AJAX::get_refreshed_fragments();
	}
}

if ( ! function_exists( 'lafka_cart_drawer_qty_take_error_notice' ) ) {
	/**
	 * The first error notice a validation callback queued (then clear them,
	 * so the refused change leaves no stale notice on the next page).
	 *
	 * @return string
	 */
	function lafka_cart_drawer_qty_take_error_notice(): string {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return '';
		}
		$notices = (array) wc_get_notices( 'error' );
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
		$first = reset( $notices );
		$text  = is_array( $first ) ? (string) ( $first['notice'] ?? '' ) : (string) $first;

		return trim( wp_strip_all_tags( $text ) );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_qty_enqueue' ) ) {
	/**
	 * Load the stepper client for a theme that opted in.
	 *
	 * @return void
	 */
	function lafka_cart_drawer_qty_enqueue() {
		if ( is_admin() || ! function_exists( 'lafka_cart_drawer_stepper_enabled' ) || ! lafka_cart_drawer_stepper_enabled() ) {
			return;
		}
		$min      = 'assets/js/lafka-cart-drawer-qty.min.js';
		$relative = function_exists( 'lafka_plugin_script_path' ) ? lafka_plugin_script_path( $min ) : $min;
		wp_enqueue_script(
			'lafka-cart-drawer-qty',
			plugins_url( $relative, LAFKA_PLUGIN_FILE ),
			array(),
			function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $relative ) : null,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'lafka-cart-drawer-qty',
			'lafkaCartQty',
			array(
				'url'        => lafka_cart_drawer_qty_endpoint( 'lafka_cart_set_qty' ),
				'refreshUrl' => lafka_cart_drawer_qty_endpoint( 'get_refreshed_fragments' ),
				'i18n'       => array(
					/* translators: 1: product name, 2: new quantity */
					'quantity' => __( '%1$s: %2$s', 'lafka-plugin' ),
					/* translators: %s: product name */
					'removed'  => __( '%s removed from your order', 'lafka-plugin' ),
					'failed'   => __( 'Sorry, that did not work. Please try again.', 'lafka-plugin' ),
				),
			)
		);
	}
}

if ( ! function_exists( 'lafka_cart_drawer_qty_endpoint' ) ) {
	/**
	 * A wc-ajax endpoint URL (no per-visitor data; safe in cached pages).
	 *
	 * @param string $action wc-ajax action.
	 * @return string
	 */
	function lafka_cart_drawer_qty_endpoint( string $action ): string {
		if ( class_exists( 'WC_AJAX' ) && method_exists( 'WC_AJAX', 'get_endpoint' ) ) {
			return (string) WC_AJAX::get_endpoint( $action );
		}

		return (string) add_query_arg( 'wc-ajax', $action, home_url( '/' ) );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_qty_init' ) ) {
	/**
	 * Register the endpoint and the client.
	 *
	 * @return void
	 */
	function lafka_cart_drawer_qty_init() {
		add_action( 'wc_ajax_lafka_cart_set_qty', 'lafka_cart_drawer_set_qty' );
		add_action( 'wp_enqueue_scripts', 'lafka_cart_drawer_qty_enqueue', 20 );
	}
}
