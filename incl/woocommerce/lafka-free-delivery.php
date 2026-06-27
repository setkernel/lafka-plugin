<?php
/**
 * Free delivery over $X — standalone, always-loaded.
 *
 * Deliberately NOT part of the gated Lafka_Promotions module (that module also
 * carries an unrelated BOGO banner). Free delivery is its own independently
 * toggled feature: it activates purely when the threshold is > 0, so an operator
 * can offer "free delivery over $X" without turning on anything else.
 *
 * The threshold is the SSOT for both this shipping rule and the storefront
 * "free over $X" copy/progress (theme), so the promise and the rule can never
 * diverge. Source order: explicit filter → promotions knob (if that module is
 * on) → Customizer theme_mods → 0 (off).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_get_free_delivery_threshold' ) ) {
	/**
	 * SSOT free-delivery threshold in store currency (0 = off).
	 *
	 * @return float
	 */
	function lafka_get_free_delivery_threshold(): float {
		$value = 0.0;
		// Optional: the promotions admin knob, only when that module is loaded.
		if ( class_exists( 'Lafka_Promotions' ) ) {
			$value = (float) Lafka_Promotions::knob( 'free_delivery_threshold' );
		}
		// Customizer (the practical, always-available storefront setting).
		if ( $value <= 0 && function_exists( 'get_theme_mod' ) ) {
			$value = (float) get_theme_mod( 'lafka_pdp_free_delivery_threshold', 0 );
			if ( $value <= 0 ) {
				$value = (float) get_theme_mod( 'lafka_announce_bar_delivery_threshold', 0 );
			}
		}
		return (float) apply_filters( 'lafka_free_delivery_threshold', max( 0.0, $value ) );
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

if ( ! function_exists( 'lafka_free_delivery_apply_rates' ) ) {
	// Priority 20: after distance-rate (and most shipping methods) have set costs.
	add_filter( 'woocommerce_package_rates', 'lafka_free_delivery_apply_rates', 20, 2 );
	/**
	 * Zero out non-pickup delivery cost (+ its taxes) when the cart qualifies.
	 *
	 * @param array $rates
	 * @param array $package
	 * @return array
	 */
	function lafka_free_delivery_apply_rates( $rates, $package ) {
		if ( ! lafka_free_delivery_eligible( (float) ( $package['contents_cost'] ?? 0 ) ) ) {
			return $rates;
		}
		foreach ( (array) $rates as $rate ) {
			if ( ! is_object( $rate ) || 'local_pickup' === $rate->method_id ) {
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
