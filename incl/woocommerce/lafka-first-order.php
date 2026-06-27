<?php
/**
 * First-order discount — standalone, always-loaded.
 *
 * An automatic percentage discount on a customer's FIRST order, to convert
 * first-time visitors (esp. those arriving from the delivery apps) into direct
 * customers. Like free-delivery, it is its own independently-toggled feature
 * (activates purely when the percent is > 0), NOT behind the BOGO module gate.
 *
 * Abuse-resistance: eligibility is intentionally limited to LOGGED-IN customers
 * with zero prior orders — a guest has no history, so a guest-eligible perk
 * could be claimed forever. Requiring an account also improves retention +
 * tracking. The eligibility test is filterable for operators who want to widen
 * it (e.g. first-order-by-billing-email at checkout).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_first_order_discount_percent' ) ) {
	/**
	 * SSOT first-order discount percent (0 = off). Source order:
	 * filter → option → Customizer theme_mod → 0.
	 *
	 * @return float 0–100.
	 */
	function lafka_first_order_discount_percent(): float {
		$percent = 0.0;
		if ( function_exists( 'get_option' ) ) {
			$percent = (float) get_option( 'lafka_first_order_discount_percent', 0 );
		}
		if ( $percent <= 0 && function_exists( 'get_theme_mod' ) ) {
			$percent = (float) get_theme_mod( 'lafka_first_order_discount_percent', 0 );
		}
		$percent = (float) apply_filters( 'lafka_first_order_discount_percent', $percent );
		return min( 100.0, max( 0.0, $percent ) );
	}
}

if ( ! function_exists( 'lafka_is_first_order_customer' ) ) {
	/**
	 * Whether the current visitor qualifies as a first-time customer.
	 * Logged-in + zero prior orders. Filterable.
	 *
	 * @return bool
	 */
	function lafka_is_first_order_customer(): bool {
		$eligible = false;
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wc_get_customer_order_count' ) ) {
			$eligible = 0 === (int) wc_get_customer_order_count( get_current_user_id() );
		}
		return (bool) apply_filters( 'lafka_is_first_order_customer', $eligible );
	}
}

if ( ! function_exists( 'lafka_first_order_eligible' ) ) {
	/** @return bool Feature on AND visitor qualifies. */
	function lafka_first_order_eligible(): bool {
		return lafka_first_order_discount_percent() > 0 && lafka_is_first_order_customer();
	}
}

if ( ! function_exists( 'lafka_first_order_discount_amount' ) ) {
	/**
	 * Pure discount math (testable): percent of a subtotal, 2dp, never negative.
	 *
	 * @param float $subtotal
	 * @param float $percent
	 * @return float
	 */
	function lafka_first_order_discount_amount( float $subtotal, float $percent ): float {
		if ( $percent <= 0 || $subtotal <= 0 ) {
			return 0.0;
		}
		return round( $subtotal * ( min( 100.0, $percent ) / 100 ), 2 );
	}
}

if ( ! function_exists( 'lafka_first_order_apply_discount' ) ) {
	add_action( 'woocommerce_cart_calculate_fees', 'lafka_first_order_apply_discount' );
	/**
	 * Apply the first-order discount as a negative cart fee.
	 *
	 * @param \WC_Cart $cart
	 * @return void
	 */
	function lafka_first_order_apply_discount( $cart ) {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! is_object( $cart ) || ! lafka_first_order_eligible() ) {
			return;
		}
		$percent = lafka_first_order_discount_percent();
		$amount  = lafka_first_order_discount_amount( (float) $cart->get_subtotal(), $percent );
		if ( $amount > 0 ) {
			$cart->add_fee(
				sprintf(
					/* translators: %s = discount percent, e.g. 15 */
					__( 'First-order discount (%s%% off)', 'lafka-plugin' ),
					(string) ( (float) $percent )
				),
				-$amount,
				false
			);
		}
	}
}
