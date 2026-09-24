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
