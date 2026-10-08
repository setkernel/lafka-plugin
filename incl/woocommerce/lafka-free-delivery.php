<?php
/**
 * Free delivery over $X — standalone, always-loaded.
 *
 * Deliberately NOT part of the gated Lafka_Promotions module (that module also
 * carries an unrelated BOGO banner). Free delivery is its own independently
 * toggled feature: it activates purely when the threshold is > 0, so an operator
 * can offer "free delivery over $X" without turning on anything else.
 *
 * The threshold is the SSOT for the storefront "free over $X" copy/progress
 * (theme) and for the Lafka distance method's own free-over rule
 * (Lafka_Shipping_Method_Distance), so the promise and the rule can never
 * diverge. Source order: the customer's zone Free Shipping minimum order amount
 * (WooCommerce's native free-delivery rule) → operator option → promotions knob
 * (if that module is on) → 0 (off), then the filter.
 *
 * WooCommerce's own Free Shipping method ("a minimum order amount") is the native
 * route to free delivery for any other method; this file never changes the rates
 * of a method it does not own. A shop can opt further methods in with the
 * `lafka_free_delivery_method_ids` filter (none by default).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';

if ( ! function_exists( 'lafka_get_free_delivery_threshold' ) ) {
	/**
	 * SSOT free-delivery threshold in store currency (0 = off). Every surface that
	 * cites or enforces the threshold reads it here (the theme through
	 * lafka_free_delivery_threshold()).
	 *
	 * @return float
	 */
	function lafka_get_free_delivery_threshold(): float {
		// WooCommerce → Settings → Restaurant → Promotions (operator UI).
		$value = (float) get_option( 'lafka_free_delivery_threshold', 0 );
		// Optional: the promotions admin knob, only when that module is loaded.
		if ( $value <= 0 && class_exists( 'Lafka_Promotions' ) ) {
			$value = (float) Lafka_Promotions::knob( 'free_delivery_threshold' );
		}
		// The zone's own Free Shipping rule, when it has one, is what the customer
		// is promised: the progress bar and copy follow the method's real amount.
		$zone_amount = lafka_free_delivery_zone_min_amount();
		if ( $zone_amount > 0 ) {
			$value = $zone_amount;
		}
		$value = max( 0.0, $value );
		// Deprecated: child overrides keyed to the legacy filter keep working here, the
		// one place the threshold is resolved, until they move to the canonical filter.
		$value = (float) apply_filters_deprecated( 'lafka_pdp_free_delivery_threshold', array( $value ), '10.4.0', 'lafka_free_delivery_threshold' );
		return (float) apply_filters( 'lafka_free_delivery_threshold', max( 0.0, $value ) );
	}
}

if ( ! function_exists( 'lafka_free_delivery_zone_min_amount' ) ) {
	/**
	 * The minimum order amount of the Free Shipping method in the shipping zone
	 * the customer's address falls in (WooCommerce > Settings > Shipping > zone >
	 * Free Shipping > "A minimum order amount"); 0 when the zone has no such
	 * method (or it needs a coupon only).
	 *
	 * @since 10.4.0
	 *
	 * @return float
	 */
	function lafka_free_delivery_zone_min_amount(): float {
		static $cache = array();
		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->customer ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0.0;
		}
		$destination = array(
			'country'  => (string) WC()->customer->get_shipping_country(),
			'state'    => (string) WC()->customer->get_shipping_state(),
			'postcode' => (string) WC()->customer->get_shipping_postcode(),
			'city'     => (string) WC()->customer->get_shipping_city(),
		);
		$key         = md5( (string) wp_json_encode( $destination ) );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$amount = 0.0;
		$zone   = WC_Shipping_Zones::get_zone_matching_package( array( 'destination' => $destination ) );
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( 'free_shipping' !== $method->id || ! in_array( $method->get_option( 'requires' ), array( 'min_amount', 'either', 'both' ), true ) ) {
				continue;
			}
			$amount = (float) $method->get_option( 'min_amount', 0 );
			break;
		}
		$cache[ $key ] = max( 0.0, $amount );

		return $cache[ $key ];
	}
}

if ( ! function_exists( 'lafka_free_delivery_eligible' ) ) {
	/**
	 * Whether a cart's package contents qualify for free delivery.
	 * Boundary `>=`; threshold 0 = off (never eligible).
	 *
	 * @param float|int $contents_cost
	 * @return bool
	 */
	function lafka_free_delivery_eligible( $contents_cost ): bool {
		$threshold = lafka_get_free_delivery_threshold();
		return $threshold > 0 && (float) $contents_cost >= $threshold;
	}
}

if ( ! function_exists( 'lafka_free_delivery_method_ids' ) ) {
	/**
	 * Shipping method ids whose delivery rate is zeroed once the cart reaches the
	 * free-delivery threshold. Empty by default: WooCommerce's Free Shipping method
	 * and the Lafka distance method each carry their own free-over rule, and a
	 * method this plugin does not own is never changed unless the shop opts it in.
	 *
	 * @since 10.4.0
	 *
	 * @return string[]
	 */
	function lafka_free_delivery_method_ids(): array {
		/**
		 * Filter the shipping method ids made free over the free-delivery threshold.
		 *
		 * @since 10.4.0
		 * @param string[] $method_ids Shipping method ids (e.g. 'flat_rate'). Default none.
		 */
		return array_map( 'strval', (array) apply_filters( 'lafka_free_delivery_method_ids', array() ) );
	}
}

if ( ! function_exists( 'lafka_free_delivery_apply_rates' ) ) {
	// Priority 20: after the opted-in methods have set their costs.
	add_filter( 'woocommerce_package_rates', 'lafka_free_delivery_apply_rates', 20, 2 );
	/**
	 * Zero out the delivery cost (+ its taxes) of the methods opted in through
	 * lafka_free_delivery_method_ids() when the cart qualifies.
	 *
	 * @param array $rates
	 * @param array $package
	 * @return array
	 */
	function lafka_free_delivery_apply_rates( $rates, $package ) {
		$method_ids = lafka_free_delivery_method_ids();
		if ( array() === $method_ids || ! lafka_free_delivery_eligible( (float) ( $package['contents_cost'] ?? 0 ) ) ) {
			return $rates;
		}
		foreach ( (array) $rates as $rate ) {
			// The distance method applies its own free-over rule, whatever the filter says.
			if ( ! is_object( $rate ) || 'lafka_distance' === $rate->method_id || ! in_array( $rate->method_id, $method_ids, true ) ) {
				continue;
			}
			$rate->cost = 0;
			if ( ! empty( $rate->taxes ) && is_array( $rate->taxes ) ) {
				$rate->taxes = array_map(
					static function () {
						return 0;
					},
					$rate->taxes
				);
			}
		}
		return $rates;
	}
}
