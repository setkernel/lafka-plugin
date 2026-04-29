<?php
/**
 * Prep-time trust signal — "Ready in X min".
 *
 * Per-category override via lafka_pdp_prep_time_<slug>; falls back to
 * lafka_pdp_prep_time_default. When closed, copy switches to
 * "Closed — order ahead".
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_get_prep_time' ) ) {
	function lafka_pdp_get_prep_time( int $product_id ): int {
		$default = (int) get_theme_mod( 'lafka_pdp_prep_time_default', 25 );

		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return $default;
		}
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $default;
		}
		foreach ( $terms as $slug ) {
			$key = 'lafka_pdp_prep_time_' . sanitize_key( $slug );
			$val = get_theme_mod( $key, null );
			if ( null !== $val && '' !== $val ) {
				return (int) $val;
			}
		}
		return $default;
	}
}

if ( ! function_exists( 'lafka_pdp_is_store_open' ) ) {
	function lafka_pdp_is_store_open(): bool {
		if ( ! function_exists( 'lafka_get_restaurant_info' ) ) {
			return true;
		}
		$info = lafka_get_restaurant_info();
		if ( empty( $info['hours'] ) || ! is_array( $info['hours'] ) ) {
			return true;
		}
		$today = wp_date( 'l' );
		$today_hours = $info['hours'][ $today ] ?? '';
		if ( '' === $today_hours || strtolower( $today_hours ) === 'closed' ) {
			return false;
		}
		if ( ! preg_match( '/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $today_hours, $m ) ) {
			return true;
		}
		$now = wp_date( 'H:i' );
		return $now >= $m[1] && $now < $m[2];
	}
}

if ( ! function_exists( 'lafka_pdp_render_prep_time' ) ) {
	function lafka_pdp_render_prep_time( int $product_id ): void {
		if ( ! lafka_pdp_is_store_open() ) {
			printf(
				'<span class="lafka-pdp-trust lafka-pdp-trust--closed">%s</span>',
				esc_html__( 'Closed — order ahead', 'lafka-plugin' )
			);
			return;
		}
		$minutes = lafka_pdp_get_prep_time( $product_id );
		printf(
			'<span class="lafka-pdp-trust lafka-pdp-trust--open">⏱ %s</span>',
			esc_html( sprintf( __( 'Ready in ~%d min', 'lafka-plugin' ), $minutes ) )
		);
	}
}
