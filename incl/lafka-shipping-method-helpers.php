<?php
/**
 * Shipping-method helpers shared by every module that distinguishes pickup
 * from delivery (shipping areas, Store API gates, promotions, free delivery,
 * the kitchen display).
 *
 * @package Lafka\Plugin
 * @since   10.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_is_pickup_shipping_method' ) ) {
	/**
	 * Whether a shipping method (id or chosen rate id) is a customer pickup.
	 *
	 * Recognises both WooCommerce pickup methods: the classic `local_pickup`
	 * and the Cart/Checkout-blocks `pickup_location`. Accepts a bare method id
	 * (`local_pickup`) or a chosen rate id (`local_pickup:3`,
	 * `pickup_location:0`).
	 *
	 * @param string $method Method id or rate id.
	 * @return bool
	 */
	function lafka_is_pickup_shipping_method( $method ) {
		$method_id = strtok( (string) $method, ':' );
		$pickup    = (array) apply_filters( 'lafka_pickup_shipping_method_ids', array( 'local_pickup', 'pickup_location' ) );

		return false !== $method_id && in_array( $method_id, $pickup, true );
	}
}

if ( ! function_exists( 'lafka_fulfilment_type_for' ) ) {
	/**
	 * How a cart will be fulfilled: 'pickup', 'delivery', or '' when nothing
	 * is chosen yet. The Lafka order type (branch/order-type selector) decides
	 * when set; otherwise the order is a pickup only when every chosen
	 * shipping rate is a pickup method.
	 *
	 * @param string[] $chosen_methods Chosen shipping rate ids.
	 * @param string   $order_type     Lafka order type ('pickup', 'delivery' or '').
	 * @return string
	 */
	function lafka_fulfilment_type_for( array $chosen_methods, string $order_type ): string {
		if ( 'pickup' === $order_type || 'delivery' === $order_type ) {
			return $order_type;
		}
		$chosen_methods = array_filter( array_map( 'strval', $chosen_methods ) );
		if ( empty( $chosen_methods ) ) {
			return '';
		}
		foreach ( $chosen_methods as $method ) {
			if ( ! lafka_is_pickup_shipping_method( $method ) ) {
				return 'delivery';
			}
		}

		return 'pickup';
	}
}

if ( ! function_exists( 'lafka_current_fulfilment_type' ) ) {
	/**
	 * lafka_fulfilment_type_for() for the current request: the shipping
	 * choice posted with a classic checkout submit / order-review refresh
	 * when present, else the WC session's chosen rates, plus the session's
	 * Lafka order type.
	 *
	 * @return string 'pickup', 'delivery' or ''.
	 */
	function lafka_current_fulfilment_type(): string {
		$wc      = function_exists( 'WC' ) ? WC() : null;
		$session = ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only decision; WooCommerce verifies its own nonce before it acts on a checkout / order-review request.
		if ( isset( $_POST['shipping_method'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
			$chosen = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shipping_method'] ) );
		} else {
			$chosen = null === $session ? array() : (array) $session->get( 'chosen_shipping_methods' );
		}

		$branch     = null === $session ? null : $session->get( 'lafka_branch_location' );
		$order_type = is_array( $branch ) ? (string) ( $branch['order_type'] ?? '' ) : '';

		return lafka_fulfilment_type_for( $chosen, $order_type );
	}
}

if ( ! function_exists( 'lafka_order_fulfilment_type' ) ) {
	/**
	 * How an order is fulfilled: 'pickup' or 'delivery' (or the Lafka order
	 * type stored on the order, when one was chosen at checkout).
	 *
	 * Falls back to the order's shipping lines: any WC pickup method (classic
	 * or blocks) means pickup; an order with no shipping line at all is
	 * collected too.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	function lafka_order_fulfilment_type( $order ) {
		$type = $order->get_meta( 'lafka_order_type' );
		if ( $type ) {
			return (string) $type;
		}

		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return 'pickup';
		}
		foreach ( $shipping_methods as $method ) {
			if ( lafka_is_pickup_shipping_method( $method->get_method_id() ) ) {
				return 'pickup';
			}
		}

		return 'delivery';
	}
}
