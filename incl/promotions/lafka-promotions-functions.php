<?php
/**
 * Promotions helper function.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lafka_delivery_minimum' ) ) {
	/**
	 * The order subtotal below which delivery is not offered (0 = none): the one
	 * reader of the Promotions delivery-minimum setting. The rate-hiding rule,
	 * the cart notice, the drawer's Delivery note, the Store API and the checkout
	 * all ask here. 0 while the Promotions module is off.
	 *
	 * @return float
	 */
	function lafka_delivery_minimum(): float {
		$minimum = ( ! function_exists( 'is_lafka_promotions' ) || is_lafka_promotions() ) ? max( 0.0, (float) Lafka_Promotions::knob( 'delivery_min' ) ) : 0.0;

		/**
		 * Filter the delivery minimum (shown to customers and enforced).
		 *
		 * @since 10.3.0
		 * @param float $minimum Order subtotal needed for delivery (0 = none).
		 */
		return (float) apply_filters( 'lafka_delivery_minimum', $minimum );
	}
}
