<?php
/**
 * The store location delivery areas measure from.
 *
 * Shipping Areas → Advanced → "Set Store Location" is either "geocode the
 * WooCommerce store address" or "pick the location on a map". The map picker
 * used to fall back to a hard-coded Sydney, Australia position and write it
 * into the setting as soon as the page opened, so saving the settings page
 * persisted Sydney as the store — and every radius check measured from there.
 *
 * lafka_store_location_settings() is the one reader: a picked location is
 * used only when it is a valid coordinate pair and not that legacy
 * placeholder; otherwise the store is "not configured" and delivery maths
 * falls back to geocoding the WooCommerce store address (the other supported
 * mode) instead of measuring from a wrong point. The operator is told through
 * an admin notice and a Site Health check.
 *
 * @package Lafka\Plugin\ShippingAreas
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_parse_store_map_location' ) ) {
	/**
	 * Parse a saved store location (URL-encoded JSON {lat,lng}, as the admin
	 * map writes it, or plain JSON). Null when missing, malformed, out of
	 * range, or the legacy Sydney placeholder.
	 *
	 * @param mixed $raw Saved value.
	 * @return array{lat:float,lng:float}|null
	 */
	function lafka_parse_store_map_location( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$decoded = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['lat'], $decoded['lng'] ) || ! is_numeric( $decoded['lat'] ) || ! is_numeric( $decoded['lng'] ) ) {
			return null;
		}
		$lat = (float) $decoded['lat'];
		$lng = (float) $decoded['lng'];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}
		if ( lafka_is_legacy_store_location_placeholder( $lat, $lng ) ) {
			return null;
		}

		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}
}

if ( ! function_exists( 'lafka_is_legacy_store_location_placeholder' ) ) {
	/**
	 * Whether a coordinate is the Sydney default the old admin map saved on
	 * its own (Google's geocode of "Sydney"). No store pins that exact point.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool
	 */
	function lafka_is_legacy_store_location_placeholder( float $lat, float $lng ): bool {
		return abs( $lat - -33.8688197 ) < 1e-6 && abs( $lng - 151.2092955 ) < 1e-6;
	}
}

if ( ! function_exists( 'lafka_store_location_settings' ) ) {
	/**
	 * The effective store-location configuration.
	 *
	 * @return array{status:string,mode:string,location:string} status: 'ok' (a
	 *         valid picked location), 'geocode' (geocode mode chosen),
	 *         'missing' / 'placeholder' (pick mode without a usable location);
	 *         mode: the mode delivery maths should use; location: the saved
	 *         value to use ('' unless status is 'ok').
	 */
	function lafka_store_location_settings(): array {
		$options = get_option( 'lafka_shipping_areas_advanced' );
		$options = is_array( $options ) ? $options : array();
		$mode    = empty( $options['set_store_location'] ) ? 'geo_woo_store' : (string) $options['set_store_location'];

		if ( 'pick_store_address' !== $mode ) {
			return array(
				'status'   => 'geocode',
				'mode'     => 'geo_woo_store',
				'location' => '',
			);
		}

		$raw = isset( $options['store_map_location'] ) ? $options['store_map_location'] : '';
		if ( null !== lafka_parse_store_map_location( $raw ) ) {
			return array(
				'status'   => 'ok',
				'mode'     => 'pick_store_address',
				'location' => (string) $raw,
			);
		}

		$decoded = is_string( $raw ) ? json_decode( rawurldecode( $raw ), true ) : null;
		$legacy  = is_array( $decoded ) && isset( $decoded['lat'], $decoded['lng'] ) && is_numeric( $decoded['lat'] ) && is_numeric( $decoded['lng'] )
			&& lafka_is_legacy_store_location_placeholder( (float) $decoded['lat'], (float) $decoded['lng'] );

		return array(
			'status'   => $legacy ? 'placeholder' : 'missing',
			'mode'     => 'geo_woo_store',
			'location' => '',
		);
	}
}

if ( ! function_exists( 'lafka_store_location_problem' ) ) {
	/**
	 * Operator-facing explanation when the picked location is unusable ('' when fine).
	 *
	 * @return string
	 */
	function lafka_store_location_problem(): string {
		$status = lafka_store_location_settings()['status'];
		if ( 'placeholder' === $status ) {
			return __( 'The saved store location is the old built-in placeholder (Sydney, Australia), not your store. Until you pin your store on the map, delivery distances are measured from a geocode of your WooCommerce store address.', 'lafka-plugin' );
		}
		if ( 'missing' === $status ) {
			return __( 'No store location has been pinned on the map yet. Until you pin your store, delivery distances are measured from a geocode of your WooCommerce store address.', 'lafka-plugin' );
		}

		return '';
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
