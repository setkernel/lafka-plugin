<?php
/**
 * Prep-time trust signal — "Ready in X min".
 *
 * Per-category override via lafka_pdp_prep_time_<slug>; falls back to
 * lafka_pdp_prep_time_default. When closed, it says "Closed — order ahead"
 * only if a later slot can be ordered; otherwise the store-closed notice
 * already says when ordering opens, so nothing is printed.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_get_prep_time' ) ) {
	function lafka_pdp_get_prep_time( int $product_id ): int {
		$default = (int) lafka_setting( 'lafka_pdp_prep_time_default', 25 );

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $default;
		}
		foreach ( $terms as $slug ) {
			$key = 'lafka_pdp_prep_time_' . sanitize_key( $slug );
			$val = lafka_setting( $key, null );
			if ( null !== $val && '' !== $val ) {
				return (int) $val;
			}
		}
		return $default;
	}
}

if ( ! function_exists( 'lafka_pdp_is_store_open' ) ) {
	/**
	 * Whether the store is open right now, for the PDP trust line and the
	 * page-context event. A thin reader of Lafka_Order_Hours::status(), the same
	 * answer the header badge, schema and order gate give.
	 *
	 * Filter `lafka_pdp_is_store_open` (bool $open) adjusts the result.
	 *
	 * @return bool
	 */
	function lafka_pdp_is_store_open(): bool {
		$open = ! class_exists( 'Lafka_Order_Hours' ) || Lafka_Order_Hours::status()['is_open'];

		return (bool) apply_filters( 'lafka_pdp_is_store_open', $open );
	}
}

if ( ! function_exists( 'lafka_pdp_render_prep_time' ) ) {
	function lafka_pdp_render_prep_time( int $product_id ): void {
		if ( ! lafka_pdp_is_store_open() ) {
			if ( class_exists( 'Lafka_Order_Hours' ) && Lafka_Order_Hours::can_order_ahead() ) {
				printf(
					'<span class="lafka-pdp-trust lafka-pdp-trust--closed">%s</span>',
					esc_html__( 'Closed — order ahead', 'lafka-plugin' )
				);
			}
			return;
		}
		$minutes = lafka_pdp_get_prep_time( $product_id );
		/* translators: %d: minutes until the order is ready. */
		$text = sprintf( __( 'Ready in ~%d min', 'lafka-plugin' ), $minutes );
		/**
		 * Filter the PDP ready-time line, e.g. so a theme's store-wide ETA
		 * ("20–30 min") is the one source the whole storefront quotes.
		 *
		 * @param string $text       "Ready in ~25 min".
		 * @param int    $minutes    Resolved prep minutes.
		 * @param int    $product_id Product.
		 */
		$text = (string) apply_filters( 'lafka_pdp_prep_time_text', $text, $minutes, $product_id );
		printf(
			'<span class="lafka-pdp-trust lafka-pdp-trust--open">⏱ %s</span>',
			esc_html( $text )
		);
	}
}
