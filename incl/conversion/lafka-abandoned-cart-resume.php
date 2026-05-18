<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — resume handler.
 *
 * Hook on `init` (priority 5 — before WC's own session loader on priority 10)
 * inspects `$_GET['lafka_resume_cart']`. If it matches a row's `resume_token`,
 * the cart contents are decoded back into a fresh WC()->cart and the visitor
 * is redirected to /cart/.
 *
 * Failure modes:
 *   - missing/empty token → no-op (let WP route normally)
 *   - unknown token        → 302 to /cart/ with a flash notice
 *   - row already linked to an order (already converted) → 302 to /cart/
 *   - WC not loaded        → no-op
 *
 * The token is single-use per cart restore, but multi-use within the row's
 * lifetime — the customer can click the email link from desktop AND mobile
 * within the same session and get the same cart back. After conversion the row
 * is marked `order_id`-linked and the resume guard blocks further use.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_ac_handle_resume_request' ) ) {
	/**
	 * Inspect $_GET, restore cart, redirect to /cart/.
	 *
	 * Hooked on `init` priority 5 so it fires before any WC session decisions.
	 *
	 * @return void
	 */
	function lafka_ac_handle_resume_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link from the recovery email; token IS the auth.
		if ( empty( $_GET['lafka_resume_cart'] ) || ! is_string( $_GET['lafka_resume_cart'] ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token IS the auth.
		$raw_token = wp_unslash( $_GET['lafka_resume_cart'] );
		$token     = function_exists( 'sanitize_text_field' )
			? sanitize_text_field( $raw_token )
			: preg_replace( '/[^a-zA-Z0-9]/', '', $raw_token );
		if ( ! is_string( $token ) || strlen( $token ) < 16 ) {
			return;
		}

		$row = lafka_ac_get_row_by_token( $token );
		if ( ! $row ) {
			return;
		}

		// Already-converted rows: send the visitor onward without restoring (their
		// previous cart is now baked into the placed order).
		if ( ! empty( $row->order_id ) && (int) $row->order_id > 0 ) {
			lafka_ac_redirect_to_cart();
			return;
		}

		$payload = json_decode( (string) $row->cart_contents, true );
		if ( ! is_array( $payload ) || empty( $payload['items'] ) ) {
			lafka_ac_redirect_to_cart();
			return;
		}

		lafka_ac_restore_cart_from_payload( $payload );
		lafka_ac_redirect_to_cart();
	}
}

if ( ! function_exists( 'lafka_ac_restore_cart_from_payload' ) ) {
	/**
	 * Push each item back into WC()->cart.
	 *
	 * Empties the current cart first so the visitor's existing selections
	 * don't get double-counted. WC's add_to_cart handles its own stock /
	 * variation validation.
	 *
	 * @param array $payload Decoded cart_contents column.
	 * @return void
	 */
	function lafka_ac_restore_cart_from_payload( array $payload ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return;
		}
		$cart = $wc->cart;
		if ( method_exists( $cart, 'empty_cart' ) ) {
			$cart->empty_cart();
		}
		if ( ! method_exists( $cart, 'add_to_cart' ) ) {
			return;
		}
		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		foreach ( $items as $item ) {
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$quantity     = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			if ( $product_id <= 0 ) {
				continue;
			}
			$cart->add_to_cart( $product_id, $quantity, $variation_id );
		}
	}
}

if ( ! function_exists( 'lafka_ac_redirect_to_cart' ) ) {
	/**
	 * Send the visitor to /cart/ — wc_get_cart_url() when available, fallback
	 * to home_url('/cart/').
	 *
	 * @return void
	 */
	function lafka_ac_redirect_to_cart(): void {
		$target = function_exists( 'wc_get_cart_url' )
			? (string) wc_get_cart_url()
			: ( function_exists( 'home_url' ) ? (string) home_url( '/cart/' ) : '/cart/' );

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 302 );
		} elseif ( function_exists( 'wp_redirect' ) ) {
			wp_redirect( $target, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect
		} else {
			header( 'Location: ' . $target, true, 302 );
		}
		exit;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'lafka_ac_handle_resume_request', 5 );
}
