<?php
/**
 * Map script handles (front end + admin).
 *
 *   leaflet            Leaflet 1.9.4 (vendored, BSD-2-Clause), keyless provider.
 *   lafka-google-maps  The Google Maps JS API loader, only when a key is set.
 *   lafka-maps         The plugin's map façade (assets/js/lafka-maps.js): one
 *                      API over either provider (maps, markers, polygons, the
 *                      zone editor, geocoding, polyline encode/decode,
 *                      distance and point-in-polygon), plus the start view.
 *
 * A feature that draws a map calls lafka_enqueue_maps() and lists
 * 'lafka-maps' as a dependency; the façade loads whichever provider
 * lafka_maps_provider() names, and window.lafkaMapDefaults carries
 * lafka_get_map_default_view() — the one start view every map uses. A
 * feature that only geocodes or measures (the location popup) calls
 * lafka_enqueue_maps( false ): without a key no map library loads at all.
 *
 * Tiles: OpenStreetMap's standard tile server by default
 * (`lafka_map_tile_url`, `lafka_map_tile_attribution`). Tiles load only for
 * the visible map; nothing prefetches or caches them beyond the browser.
 *
 * @package Lafka\Plugin\Geo
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'lafka_register_map_assets', 5 );
add_action( 'admin_enqueue_scripts', 'lafka_register_map_assets', 5 );

if ( ! function_exists( 'lafka_google_maps_script_url' ) ) {
	/**
	 * Google Maps JS API loader URL for the configured key, or '' when the
	 * provider is not Google (no key).
	 *
	 * @param string $libraries Comma-separated Maps libraries ('' for none).
	 * @return string
	 */
	function lafka_google_maps_script_url( $libraries ) {
		if ( 'google' !== lafka_maps_provider() ) {
			return '';
		}

		return 'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( lafka_google_maps_key() )
			. ( '' === (string) $libraries ? '' : '&libraries=' . rawurlencode( (string) $libraries ) )
			. '&v=weekly&language=' . rawurlencode( get_locale() )
			. '&callback=Function.prototype';
	}
}

if ( ! function_exists( 'lafka_register_map_assets' ) ) {
	/**
	 * Register Leaflet, the Google loader (with a key) and the façade.
	 *
	 * @return void
	 */
	function lafka_register_map_assets() {
		$leaflet = 'assets/js/leaflet/leaflet.js';
		wp_register_script( 'leaflet', plugins_url( $leaflet, LAFKA_PLUGIN_FILE ), array(), '1.9.4', true );
		wp_register_style( 'leaflet', plugins_url( 'assets/js/leaflet/leaflet.css', LAFKA_PLUGIN_FILE ), array(), '1.9.4' );

		$provider = lafka_maps_provider();
		$deps     = array();
		if ( 'google' === $provider ) {
			// Places drives the branch modal's address autocomplete (front end only).
			$url = lafka_google_maps_script_url( is_admin() ? '' : 'places' );
			wp_register_script(
				'lafka-google-maps',
				$url,
				array(),
				'weekly',
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			$deps[] = 'lafka-google-maps';
		}

		$script = lafka_plugin_script_path( 'assets/js/lafka-maps.min.js' );
		wp_register_script( 'lafka-maps', plugins_url( $script, LAFKA_PLUGIN_FILE ), $deps, lafka_plugin_asset_version( $script ), true );
		wp_register_style( 'lafka-maps', plugins_url( 'assets/css/lafka-maps.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/css/lafka-maps.css' ) );
	}
}

if ( ! function_exists( 'lafka_maps_client_config' ) ) {
	/**
	 * The façade's configuration (window.lafkaMapsConfig).
	 *
	 * @return array
	 */
	function lafka_maps_client_config(): array {
		$admin = is_admin();

		return array(
			'provider'    => lafka_maps_provider(),
			/**
			 * Filter the map tile URL template the keyless maps use.
			 *
			 * @since 10.4.0
			 * @param string $url Leaflet tile URL template ({s}, {z}, {x}, {y}).
			 */
			'tileUrl'     => (string) apply_filters( 'lafka_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ),
			/**
			 * Filter the attribution shown on the keyless maps (HTML).
			 *
			 * @since 10.4.0
			 * @param string $html Attribution required by the tile provider.
			 */
			'attribution' => wp_kses(
				(string) apply_filters( 'lafka_map_tile_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' ),
				array(
					'a' => array(
						'href'   => true,
						'rel'    => true,
						'target' => true,
					),
				)
			),
			'maxZoom'     => 19,
			'geocodeUrl'  => esc_url_raw( rest_url( $admin ? 'lafka/v1/admin/geocode' : 'lafka/v1/geocode' ) ),
			'nonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'i18n'        => array(
				'notFound'      => __( 'No address found. Place the pin on the map.', 'lafka-plugin' ),
				'geocodeFailed' => __( 'The address lookup did not answer. Place the pin on the map.', 'lafka-plugin' ),
				'locateFailed'  => __( 'Your location is not available. Place the pin on the map.', 'lafka-plugin' ),
			),
		);
	}
}

if ( ! function_exists( 'lafka_enqueue_maps' ) ) {
	/**
	 * Enqueue the map façade (and through it the provider) with its start
	 * view and configuration. Safe to call more than once.
	 *
	 * @param bool $draw Whether the page draws a map. Geocoding and distance
	 *                   checks alone need no map library with the keyless
	 *                   provider (Leaflet loads only to draw).
	 * @return bool False when maps are switched off (`lafka_maps_provider` = 'none').
	 */
	function lafka_enqueue_maps( bool $draw = true ): bool {
		static $done = false;
		if ( ! lafka_maps_available() ) {
			return false;
		}
		if ( ! wp_script_is( 'lafka-maps', 'registered' ) ) {
			lafka_register_map_assets();
		}
		if ( $draw && 'osm' === lafka_maps_provider() ) {
			// Printed before the façade: both are footer scripts, Leaflet first.
			wp_enqueue_script( 'leaflet' );
			wp_enqueue_style( 'leaflet' );
		}
		wp_enqueue_script( 'lafka-maps' );
		wp_enqueue_style( 'lafka-maps' );
		if ( ! $done ) {
			$done = true;
			$view = lafka_get_map_default_view();
			wp_localize_script(
				'lafka-maps',
				'lafkaMapDefaults',
				array(
					'lat'  => $view['lat'],
					'lng'  => $view['lng'],
					'zoom' => $view['zoom'],
				)
			);
			wp_localize_script( 'lafka-maps', 'lafkaMapsConfig', lafka_maps_client_config() );
		}

		return true;
	}
}
