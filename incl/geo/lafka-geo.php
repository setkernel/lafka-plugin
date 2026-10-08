<?php
/**
 * Geo: the store point, the default map view, the map provider and the one
 * Google Maps key.
 *
 * Every map the plugin draws (the store picker, the delivery-zone editor,
 * branch geocoding, the branch modal, the checkout pin map and the
 * [lafka_shipping_areas] zone map) starts from lafka_get_map_default_view()
 * and draws with the provider lafka_maps_provider() names:
 *
 *   'google'  when a Google Maps key is set (lafka[google_maps_api_key]);
 *   'osm'     otherwise: Leaflet + OpenStreetMap tiles, geocoding through the
 *             plugin's Nominatim proxy (incl/geo/class-lafka-geocoder.php).
 *
 * So every map feature works without a key; a key is an optional upgrade
 * (Google tiles, Places autocomplete in the branch modal).
 *
 * The store point is the business geo (lafka_business_geo_lat / _lng, read
 * through lafka_get_restaurant_info()), the same point the schema, the footer
 * and the directions links use. A store location pinned on the Shipping
 * Settings map before 10.4 (lafka_shipping_areas_advanced.store_map_location)
 * is still read when the business geo is empty.
 *
 * Always loaded (not only with the shipping-areas module): the schema, the
 * Site Health checks and the asset registration read it too.
 *
 * @package Lafka\Plugin\Geo
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_parse_store_map_location' ) ) {
	/**
	 * Parse a saved point: URL-encoded JSON {lat,lng} (as the admin maps write
	 * it) or plain JSON. Null when missing, malformed, out of range, or the
	 * legacy Sydney placeholder.
	 *
	 * @param mixed $raw Saved value.
	 * @return array{lat:float,lng:float}|null
	 */
	function lafka_parse_store_map_location( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$decoded = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['lat'], $decoded['lng'] ) ) {
			return null;
		}

		return lafka_geo_point( $decoded['lat'], $decoded['lng'] );
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

if ( ! function_exists( 'lafka_geo_point' ) ) {
	/**
	 * A validated point from two numeric values: null when either is not
	 * numeric, out of range, the 0,0 "null island" or the legacy placeholder.
	 *
	 * @param mixed $lat Latitude.
	 * @param mixed $lng Longitude.
	 * @return array{lat:float,lng:float}|null
	 */
	function lafka_geo_point( $lat, $lng ): ?array {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}
		if ( ( 0.0 === $lat && 0.0 === $lng ) || lafka_is_legacy_store_location_placeholder( $lat, $lng ) ) {
			return null;
		}

		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}
}

if ( ! function_exists( 'lafka_get_store_point' ) ) {
	/**
	 * The store's coordinates: the business geo, else a valid store location
	 * pinned on the Shipping Settings map before 10.4. Null when neither is set.
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	function lafka_get_store_point(): ?array {
		if ( function_exists( 'lafka_get_restaurant_info' ) ) {
			$info = lafka_get_restaurant_info();
			$lat  = $info['geo_lat'] ?? null;
			$lng  = $info['geo_lng'] ?? null;
		} else {
			$lat = get_option( 'lafka_business_geo_lat', null );
			$lng = get_option( 'lafka_business_geo_lng', null );
		}
		$point = lafka_geo_point( $lat, $lng );

		if ( null === $point ) {
			$advanced = get_option( 'lafka_shipping_areas_advanced' );
			$point    = is_array( $advanced ) && isset( $advanced['store_map_location'] ) ? lafka_parse_store_map_location( $advanced['store_map_location'] ) : null;
		}

		/**
		 * Filter the store point every map and distance check starts from.
		 *
		 * @since 10.4.0
		 * @param array{lat:float,lng:float}|null $point Store coordinates or null.
		 */
		$point = apply_filters( 'lafka_store_point', $point );

		return is_array( $point ) && isset( $point['lat'], $point['lng'] ) ? lafka_geo_point( $point['lat'], $point['lng'] ) : null;
	}
}

if ( ! function_exists( 'lafka_set_store_point' ) ) {
	/**
	 * Save the store point as the business geo (the one place it lives).
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool False when the point is invalid.
	 */
	function lafka_set_store_point( float $lat, float $lng ): bool {
		$point = lafka_geo_point( $lat, $lng );
		if ( null === $point ) {
			return false;
		}
		update_option( 'lafka_business_geo_lat', (string) round( $point['lat'], 7 ) );
		update_option( 'lafka_business_geo_lng', (string) round( $point['lng'], 7 ) );

		return true;
	}
}

if ( ! function_exists( 'lafka_geo_region_centroids' ) ) {
	/**
	 * Representative points of the Canadian provinces and territories and the
	 * US states (+ DC), with a zoom that shows the whole region.
	 *
	 * @return array<string, array<string, array{0:float,1:float,2:int}>> Country => state => [lat, lng, zoom].
	 */
	function lafka_geo_region_centroids(): array {
		return array(
			'CA' => array(
				'AB' => array( 53.9333, -116.5765, 5 ),
				'BC' => array( 53.7267, -127.6476, 5 ),
				'MB' => array( 53.7609, -98.8139, 5 ),
				'NB' => array( 46.5653, -66.4619, 7 ),
				'NL' => array( 53.1355, -57.6604, 5 ),
				'NS' => array( 44.6820, -63.7443, 7 ),
				'NT' => array( 64.8255, -124.8457, 4 ),
				'NU' => array( 70.2998, -83.1076, 3 ),
				'ON' => array( 51.2538, -85.3232, 5 ),
				'PE' => array( 46.5107, -63.4168, 8 ),
				'QC' => array( 52.9399, -73.5491, 5 ),
				'SK' => array( 52.9399, -106.4509, 5 ),
				'YT' => array( 64.2823, -135.0000, 5 ),
			),
			'US' => array(
				'AL' => array( 32.3182, -86.9023, 6 ),
				'AK' => array( 64.2008, -149.4937, 4 ),
				'AZ' => array( 34.0489, -111.0937, 6 ),
				'AR' => array( 35.2010, -91.8318, 6 ),
				'CA' => array( 36.7783, -119.4179, 5 ),
				'CO' => array( 39.5501, -105.7821, 6 ),
				'CT' => array( 41.6032, -73.0877, 8 ),
				'DE' => array( 38.9108, -75.5277, 8 ),
				'DC' => array( 38.9072, -77.0369, 11 ),
				'FL' => array( 27.6648, -81.5158, 6 ),
				'GA' => array( 32.1656, -82.9001, 6 ),
				'HI' => array( 19.8968, -155.5828, 6 ),
				'ID' => array( 44.0682, -114.7420, 5 ),
				'IL' => array( 40.6331, -89.3985, 6 ),
				'IN' => array( 40.2672, -86.1349, 6 ),
				'IA' => array( 41.8780, -93.0977, 6 ),
				'KS' => array( 39.0119, -98.4842, 6 ),
				'KY' => array( 37.8393, -84.2700, 6 ),
				'LA' => array( 30.9843, -91.9623, 6 ),
				'ME' => array( 45.2538, -69.4455, 6 ),
				'MD' => array( 39.0458, -76.6413, 7 ),
				'MA' => array( 42.4072, -71.3824, 7 ),
				'MI' => array( 44.3148, -85.6024, 6 ),
				'MN' => array( 46.7296, -94.6859, 6 ),
				'MS' => array( 32.3547, -89.3985, 6 ),
				'MO' => array( 37.9643, -91.8318, 6 ),
				'MT' => array( 46.8797, -110.3626, 5 ),
				'NE' => array( 41.4925, -99.9018, 6 ),
				'NV' => array( 38.8026, -116.4194, 6 ),
				'NH' => array( 43.1939, -71.5724, 7 ),
				'NJ' => array( 40.0583, -74.4057, 7 ),
				'NM' => array( 34.5199, -105.8701, 6 ),
				'NY' => array( 43.2994, -74.2179, 6 ),
				'NC' => array( 35.7596, -79.0193, 6 ),
				'ND' => array( 47.5515, -101.0020, 6 ),
				'OH' => array( 40.4173, -82.9071, 6 ),
				'OK' => array( 35.0078, -97.0929, 6 ),
				'OR' => array( 43.8041, -120.5542, 6 ),
				'PA' => array( 41.2033, -77.1945, 6 ),
				'RI' => array( 41.5801, -71.4774, 9 ),
				'SC' => array( 33.8361, -81.1637, 7 ),
				'SD' => array( 43.9695, -99.9018, 6 ),
				'TN' => array( 35.5175, -86.5804, 6 ),
				'TX' => array( 31.9686, -99.9018, 5 ),
				'UT' => array( 39.3210, -111.0937, 6 ),
				'VT' => array( 44.5588, -72.5778, 7 ),
				'VA' => array( 37.4316, -78.6569, 6 ),
				'WA' => array( 47.7511, -120.7401, 6 ),
				'WV' => array( 38.5976, -80.4549, 7 ),
				'WI' => array( 43.7844, -88.7879, 6 ),
				'WY' => array( 43.0760, -107.2903, 6 ),
			),
		);
	}
}

if ( ! function_exists( 'lafka_geo_country_centroids' ) ) {
	/**
	 * Representative points of common store countries, with a zoom that shows
	 * the whole country.
	 *
	 * @return array<string, array{0:float,1:float,2:int}> Country => [lat, lng, zoom].
	 */
	function lafka_geo_country_centroids(): array {
		return array(
			'CA' => array( 56.1304, -106.3468, 3 ),
			'US' => array( 37.0902, -95.7129, 4 ),
			'MX' => array( 23.6345, -102.5528, 5 ),
			'GB' => array( 55.3781, -3.4360, 5 ),
			'IE' => array( 53.4129, -8.2439, 6 ),
			'FR' => array( 46.2276, 2.2137, 5 ),
			'DE' => array( 51.1657, 10.4515, 5 ),
			'ES' => array( 40.4637, -3.7492, 5 ),
			'PT' => array( 39.3999, -8.2245, 6 ),
			'IT' => array( 41.8719, 12.5674, 5 ),
			'NL' => array( 52.1326, 5.2913, 7 ),
			'BE' => array( 50.5039, 4.4699, 7 ),
			'CH' => array( 46.8182, 8.2275, 7 ),
			'AT' => array( 47.5162, 14.5501, 7 ),
			'DK' => array( 56.2639, 9.5018, 6 ),
			'SE' => array( 60.1282, 18.6435, 4 ),
			'NO' => array( 60.4720, 8.4689, 4 ),
			'FI' => array( 61.9241, 25.7482, 4 ),
			'PL' => array( 51.9194, 19.1451, 5 ),
			'GR' => array( 39.0742, 21.8243, 6 ),
			'BG' => array( 42.7339, 25.4858, 7 ),
			'TR' => array( 38.9637, 35.2433, 5 ),
			'AU' => array( -25.2744, 133.7751, 4 ),
			'NZ' => array( -40.9006, 174.8860, 5 ),
			'IN' => array( 20.5937, 78.9629, 4 ),
			'AE' => array( 23.4241, 53.8478, 6 ),
			'SA' => array( 23.8859, 45.0792, 5 ),
			'ZA' => array( -30.5595, 22.9375, 5 ),
			'NG' => array( 9.0820, 8.6753, 6 ),
			'EG' => array( 26.8206, 30.8025, 5 ),
			'BR' => array( -14.2350, -51.9253, 4 ),
			'AR' => array( -38.4161, -63.6167, 4 ),
			'CL' => array( -35.6751, -71.5430, 4 ),
			'CO' => array( 4.5709, -74.2973, 5 ),
			'JP' => array( 36.2048, 138.2529, 5 ),
			'CN' => array( 35.8617, 104.1954, 4 ),
			'PH' => array( 12.8797, 121.7740, 5 ),
			'SG' => array( 1.3521, 103.8198, 11 ),
		);
	}
}

if ( ! function_exists( 'lafka_get_map_default_view' ) ) {
	/**
	 * Where every map opens when it has nothing of its own to show:
	 *
	 *   1. the store point (zoom 13);
	 *   2. the WooCommerce store address, when the geocoder has already
	 *      resolved it (cache only, never a request while a page renders);
	 *   3. the WooCommerce base province / state, for Canada and the US;
	 *   4. the WooCommerce base country;
	 *   5. Canada.
	 *
	 * @return array{lat:float,lng:float,zoom:int,source:string}
	 */
	function lafka_get_map_default_view(): array {
		$view  = null;
		$point = lafka_get_store_point();
		if ( null !== $point ) {
			$view = array(
				'lat'    => $point['lat'],
				'lng'    => $point['lng'],
				'zoom'   => 13,
				'source' => 'store',
			);
		}

		if ( null === $view && class_exists( 'Lafka_Geocoder' ) ) {
			$address = lafka_geo_wc_store_address();
			$cached  = '' === $address ? null : Lafka_Geocoder::cached_search( $address );
			if ( is_array( $cached ) ) {
				$view = array(
					'lat'    => (float) $cached['lat'],
					'lng'    => (float) $cached['lng'],
					'zoom'   => 13,
					'source' => 'store_address',
				);
			}
		}

		$base    = explode( ':', (string) get_option( 'woocommerce_default_country', '' ), 2 );
		$country = strtoupper( $base[0] );
		$state   = strtoupper( $base[1] ?? '' );

		if ( null === $view ) {
			$regions = lafka_geo_region_centroids();
			if ( isset( $regions[ $country ][ $state ] ) ) {
				$view = array(
					'lat'    => $regions[ $country ][ $state ][0],
					'lng'    => $regions[ $country ][ $state ][1],
					'zoom'   => $regions[ $country ][ $state ][2],
					'source' => 'region',
				);
			}
		}

		if ( null === $view ) {
			$countries = lafka_geo_country_centroids();
			$key       = isset( $countries[ $country ] ) ? $country : 'CA';
			$view      = array(
				'lat'    => $countries[ $key ][0],
				'lng'    => $countries[ $key ][1],
				'zoom'   => $countries[ $key ][2],
				'source' => 'CA' === $key && 'CA' !== $country ? 'fallback' : 'country',
			);
		}

		/**
		 * Filter the view every map opens on when it has nothing of its own to show.
		 *
		 * @since 10.4.0
		 * @param array{lat:float,lng:float,zoom:int,source:string} $view Default view.
		 */
		$filtered = apply_filters( 'lafka_map_default_view', $view );
		if ( is_array( $filtered ) && null !== lafka_geo_point( $filtered['lat'] ?? null, $filtered['lng'] ?? null ) ) {
			$view = array(
				'lat'    => (float) $filtered['lat'],
				'lng'    => (float) $filtered['lng'],
				'zoom'   => max( 1, min( 20, (int) ( $filtered['zoom'] ?? $view['zoom'] ) ) ),
				'source' => (string) ( $filtered['source'] ?? 'filter' ),
			);
		}

		return $view;
	}
}

if ( ! function_exists( 'lafka_geo_wc_store_address' ) ) {
	/**
	 * The WooCommerce store address as one geocodable line ('' when unset).
	 *
	 * @return string
	 */
	function lafka_geo_wc_store_address(): string {
		$base    = explode( ':', (string) get_option( 'woocommerce_default_country', '' ), 2 );
		$country = $base[0];
		$state   = $base[1] ?? '';
		if ( function_exists( 'WC' ) && isset( WC()->countries ) ) {
			$states    = WC()->countries->get_states( $country );
			$countries = WC()->countries->get_countries();
			$state     = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state;
			$country   = $countries[ $country ] ?? $country;
		}
		$street = trim( (string) get_option( 'woocommerce_store_address', '' ) . ' ' . (string) get_option( 'woocommerce_store_address_2', '' ) );
		$city   = (string) get_option( 'woocommerce_store_city', '' );
		if ( '' === $street && '' === $city ) {
			return '';
		}
		$parts = array( $street, $city, $state, (string) get_option( 'woocommerce_store_postcode', '' ), $country );

		return implode( ', ', array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}
}

if ( ! function_exists( 'lafka_google_maps_key' ) ) {
	/**
	 * The Google Maps key: lafka[google_maps_api_key], the one place it lives
	 * ('' when unset). Written by Lafka Shipping Settings and the Lafka theme's
	 * Customizer, both into this option.
	 *
	 * @return string
	 */
	function lafka_google_maps_key(): string {
		$key = class_exists( 'Lafka_Options' ) ? Lafka_Options::get( 'google_maps_api_key', '' ) : '';

		return is_string( $key ) ? trim( $key ) : '';
	}
}

if ( ! function_exists( 'lafka_set_google_maps_key' ) ) {
	/**
	 * Save (or clear, with '') the Google Maps key.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	function lafka_set_google_maps_key( string $key ): void {
		$key     = sanitize_text_field( $key );
		$options = get_option( 'lafka' );
		$options = is_array( $options ) ? $options : array();
		if ( ( $options['google_maps_api_key'] ?? '' ) === $key ) {
			return;
		}
		$options['google_maps_api_key'] = $key;
		update_option( 'lafka', $options );
		if ( class_exists( 'Lafka_Options' ) ) {
			Lafka_Options::flush();
		}
	}
}

if ( ! function_exists( 'lafka_maps_provider' ) ) {
	/**
	 * Which provider draws the maps: 'google' with a key, 'osm' (Leaflet +
	 * OpenStreetMap) otherwise. The `lafka_maps_provider` filter may force
	 * 'osm', or return 'none' to switch every map off.
	 *
	 * @return string 'google', 'osm' or 'none'.
	 */
	function lafka_maps_provider(): string {
		$provider = '' === lafka_google_maps_key() ? 'osm' : 'google';

		/**
		 * Filter the map provider.
		 *
		 * @since 10.4.0
		 * @param string $provider 'google' (a key is set) or 'osm'; return 'none' to disable maps.
		 */
		$filtered = (string) apply_filters( 'lafka_maps_provider', $provider );
		if ( 'google' === $filtered && '' === lafka_google_maps_key() ) {
			return 'osm';
		}

		return in_array( $filtered, array( 'google', 'osm', 'none' ), true ) ? $filtered : $provider;
	}
}

if ( ! function_exists( 'lafka_maps_available' ) ) {
	/**
	 * Whether a map provider is available (the keyless one always is, unless
	 * the `lafka_maps_provider` filter returns 'none').
	 *
	 * @return bool
	 */
	function lafka_maps_available(): bool {
		return 'none' !== lafka_maps_provider();
	}
}

if ( ! function_exists( 'lafka_maps_migrate_key' ) ) {
	/**
	 * One-time move of the Google Maps key into lafka[google_maps_api_key].
	 *
	 * Before 10.4 the Shipping Settings page kept its own copy
	 * (lafka_shipping_areas_general[google_maps_api_key]) plus an unused
	 * secondary key, synced to the main one by hooks that never passed a
	 * cleared key. A key found only there is moved in; both copies are then
	 * deleted, so the next run finds nothing to do.
	 *
	 * @return void
	 */
	function lafka_maps_migrate_key(): void {
		$general = get_option( 'lafka_shipping_areas_general' );
		if ( ! is_array( $general ) || ( ! array_key_exists( 'google_maps_api_key', $general ) && ! array_key_exists( 'secondary_google_maps_api_key', $general ) ) ) {
			return;
		}
		$legacy = trim( (string) ( $general['google_maps_api_key'] ?? '' ) );
		if ( '' !== $legacy && '' === lafka_google_maps_key() ) {
			lafka_set_google_maps_key( $legacy );
		}
		unset( $general['google_maps_api_key'], $general['secondary_google_maps_api_key'] );
		update_option( 'lafka_shipping_areas_general', $general );
	}
}
add_action( 'init', 'lafka_maps_migrate_key', 5 );
