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

// The pickup method list lives in Lafka_Fulfilment (which loads this file too).
require_once __DIR__ . '/checkout/class-lafka-fulfilment.php';

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

		return false !== $method_id && in_array( $method_id, Lafka_Fulfilment::pickup_method_ids(), true );
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

		// Read-only: the posted shipping choice only decides which fields apply.
		$posted = filter_input( INPUT_POST, 'shipping_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_FORCE_ARRAY );
		if ( is_array( $posted ) ) {
			$chosen = $posted;
		} else {
			$chosen = null === $session ? array() : (array) $session->get( 'chosen_shipping_methods' );
		}

		$branch     = null === $session ? null : $session->get( 'lafka_branch_location' );
		$order_type = is_array( $branch ) ? (string) ( $branch['order_type'] ?? '' ) : '';

		return lafka_fulfilment_type_for( $chosen, $order_type );
	}
}

if ( ! function_exists( 'lafka_settled_fulfilment_type' ) ) {
	/**
	 * lafka_current_fulfilment_type() once the cart totals are calculated:
	 * the WC session's chosen rates, which WooCommerce may have re-defaulted
	 * during the calculation (e.g. delivery preselected the moment an address
	 * unlocks the delivery rates). Before any calculation in this request it
	 * is the same as lafka_current_fulfilment_type().
	 *
	 * For output rendered after the totals (payment titles on the order-review
	 * refresh, the title saved on the order). Field requirements keep reading
	 * the posted choice (lafka_current_fulfilment_type).
	 *
	 * @return string 'pickup', 'delivery' or ''.
	 */
	function lafka_settled_fulfilment_type(): string {
		$wc      = function_exists( 'WC' ) ? WC() : null;
		$session = ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;

		if ( null !== $session && function_exists( 'did_action' ) && did_action( 'woocommerce_after_calculate_totals' ) ) {
			$chosen = array_filter( array_map( 'strval', (array) $session->get( 'chosen_shipping_methods' ) ) );
			if ( array() !== $chosen ) {
				$branch = $session->get( 'lafka_branch_location' );

				return lafka_fulfilment_type_for( $chosen, is_array( $branch ) ? (string) ( $branch['order_type'] ?? '' ) : '' );
			}
		}

		return lafka_current_fulfilment_type();
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

if ( ! function_exists( 'lafka_shipping_has_delivery_rate' ) ) {
	/**
	 * Whether any shipping package currently offers a delivery rate: a rate that
	 * is not a pickup method and not the "Delivery" placeholder the quote guard
	 * shows before an address exists (it cannot be ordered).
	 *
	 * @since 10.4.0
	 *
	 * @return bool
	 */
	function lafka_shipping_has_delivery_rate(): bool {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'shipping' ) || ! is_object( $wc->shipping() ) ) {
			return false;
		}
		foreach ( (array) $wc->shipping()->get_packages() as $package ) {
			foreach ( (array) ( $package['rates'] ?? array() ) as $rate_id => $rate ) {
				$method_id = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : (string) strtok( (string) $rate_id, ':' );
				if ( ! lafka_is_pickup_shipping_method( $method_id ) && 'lafka_delivery_pending' !== $method_id ) {
					return true;
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'lafka_order_pickup_address' ) ) {
	/**
	 * Where a pickup order is collected: the chosen branch's address, else
	 * the WooCommerce pickup location on the order, else the restaurant's
	 * address (lafka_get_restaurant_info()). '' when none is known.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	function lafka_order_pickup_address( $order ): string {
		$branch_id = (int) $order->get_meta( 'lafka_selected_branch_id' );
		if ( $branch_id > 0 ) {
			$branch = (string) get_term_meta( $branch_id, 'lafka_branch_address', true );
			if ( '' !== $branch ) {
				return $branch;
			}
		}
		foreach ( $order->get_shipping_methods() as $method ) {
			$address = (string) $method->get_meta( 'pickup_address' );
			if ( '' !== $address ) {
				return $address;
			}
		}
		$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
		return implode( ', ', array_filter( array( (string) ( $info['street'] ?? '' ), (string) ( $info['city'] ?? '' ), trim( ( $info['region'] ?? '' ) . ' ' . ( $info['postal'] ?? '' ) ) ) ) );
	}
}
