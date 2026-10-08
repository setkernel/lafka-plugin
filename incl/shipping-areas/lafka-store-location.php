<?php
/**
 * The store location delivery areas measure from: an admin notice and a Site
 * Health check while it is missing.
 *
 * The store point itself is lafka_get_store_point() (incl/geo/lafka-geo.php):
 * the business geo, else a valid pre-10.4 Shipping Settings pin. The old
 * admin map fell back to a hard-coded Sydney, Australia position and wrote it
 * as soon as the page opened, so many stores still have that placeholder
 * saved; it is never read as a location (lafka_geo_point() rejects it) and
 * the notice names it.
 *
 * @package Lafka\Plugin\ShippingAreas
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_store_location_problem' ) ) {
	/**
	 * Operator-facing explanation when the store has no location ('' when fine).
	 *
	 * @return string
	 */
	function lafka_store_location_problem(): string {
		if ( null !== lafka_get_store_point() ) {
			return '';
		}
		$advanced = get_option( 'lafka_shipping_areas_advanced' );
		$raw      = is_array( $advanced ) && isset( $advanced['store_map_location'] ) && is_string( $advanced['store_map_location'] ) ? json_decode( rawurldecode( $advanced['store_map_location'] ), true ) : null;
		if ( is_array( $raw ) && isset( $raw['lat'], $raw['lng'] ) && is_numeric( $raw['lat'] ) && is_numeric( $raw['lng'] ) && lafka_is_legacy_store_location_placeholder( (float) $raw['lat'], (float) $raw['lng'] ) ) {
			return __( 'The saved store location is the old built-in placeholder (Sydney, Australia), not your store. Pin your store on the map so delivery maps start there and distances are measured from it.', 'lafka-plugin' );
		}

		return __( 'Your store has no map location yet. Pin it on the map (or enter the coordinates under WooCommerce → Settings → Restaurant) so delivery maps start there and distances are measured from it.', 'lafka-plugin' );
	}
}

if ( ! function_exists( 'lafka_store_location_settings_url' ) ) {
	/**
	 * The Shipping Areas → Advanced settings screen.
	 *
	 * @return string
	 */
	function lafka_store_location_settings_url(): string {
		return admin_url( 'admin.php?page=lafka_shipping_areas_admin&tab=advanced' );
	}
}

if ( ! function_exists( 'lafka_store_location_admin_notice' ) ) {
	/**
	 * admin_notices: warn shop managers while the picked location is unusable.
	 *
	 * @return void
	 */
	function lafka_store_location_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$problem = lafka_store_location_problem();
		if ( '' === $problem ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Delivery areas need your store location.', 'lafka-plugin' ),
			esc_html( $problem ),
			esc_url( lafka_store_location_settings_url() ),
			esc_html__( 'Set the store location', 'lafka-plugin' )
		);
	}
}

if ( ! function_exists( 'lafka_store_location_site_health_test' ) ) {
	/**
	 * Site Health direct test.
	 *
	 * @return array
	 */
	function lafka_store_location_site_health_test(): array {
		$problem = lafka_store_location_problem();
		$badge   = array(
			'label' => __( 'Delivery', 'lafka-plugin' ),
			'color' => '' === $problem ? 'green' : 'orange',
		);

		if ( '' === $problem ) {
			return array(
				'label'       => __( 'Delivery areas have a store location', 'lafka-plugin' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'Delivery distances are measured from your store.', 'lafka-plugin' ) . '</p>',
				'test'        => 'lafka_store_location',
			);
		}

		return array(
			'label'       => __( 'Delivery areas need your store location', 'lafka-plugin' ),
			'status'      => 'recommended',
			'badge'       => $badge,
			'description' => '<p>' . esc_html( $problem ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( lafka_store_location_settings_url() ) . '">' . esc_html__( 'Set the store location', 'lafka-plugin' ) . '</a></p>',
			'test'        => 'lafka_store_location',
		);
	}
}

if ( ! function_exists( 'lafka_store_location_register_site_health' ) ) {
	/**
	 * site_status_tests: register the store-location check.
	 *
	 * @param mixed $tests Site Health tests.
	 * @return mixed
	 */
	function lafka_store_location_register_site_health( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct']['lafka_store_location'] = array(
			'label' => __( 'Lafka delivery store location', 'lafka-plugin' ),
			'test'  => 'lafka_store_location_site_health_test',
		);

		return $tests;
	}
}

if ( ! function_exists( 'lafka_store_location_init' ) ) {
	/**
	 * Wire the admin notice + Site Health check (Shipping Areas module on).
	 *
	 * @return void
	 */
	function lafka_store_location_init() {
		add_action( 'admin_notices', 'lafka_store_location_admin_notice' );
		add_filter( 'site_status_tests', 'lafka_store_location_register_site_health' );
	}
}
