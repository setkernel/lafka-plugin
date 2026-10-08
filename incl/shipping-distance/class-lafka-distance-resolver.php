<?php
/**
 * Lafka_Distance_Resolver: where a delivery starts, where it goes, and how far
 * that is, for the `lafka_distance` shipping method.
 *
 * Origin: the chosen branch's geocoded point when branch selection is on,
 * else the store point (lafka_get_store_point()).
 *
 * Destination: the classic checkout delivery pin when the customer (or the
 * pin map's own geocode) placed one for this address, else a server-side
 * geocode of the package destination through Lafka_Geocoder (cached). A
 * geocode that is not street-level, or lands in another country, is refused:
 * a price is never worked out from a guess.
 *
 * Distance modes:
 *   - 'straight': great-circle distance x a road factor. Keyless.
 *   - 'driving': the road distance from an OSRM server
 *     (`lafka_distance_osrm_endpoint` filter) or, when the one Google Maps key
 *     is set, Google's Routes API. Any other router can answer through the
 *     `lafka_distance_driving_meters` filter.
 *
 * Every failure is a WP_Error carrying a reason code and is logged on the
 * `shipping` channel; the method then offers no rate.
 *
 * @package Lafka\Plugin\ShippingDistance
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Distance_Resolver' ) ) {

	/**
	 * Origin, destination and distance for a delivery package.
	 */
	final class Lafka_Distance_Resolver {

		/** WC session key: the delivery pin {lat, lng, fp}. */
		const SESSION_PIN = 'lafka_distance_pin';

		/** Package key carrying the pin (part of WooCommerce's rate-cache hash). */
		const PACKAGE_PIN = 'lafka_pin';

		/** Kilometres per mile. */
		const KM_PER_MILE = 1.609344;

		/** Seconds a road distance and a geocoder failure are remembered. */
		const SHORT_CACHE = 600;

		/**
		 * The point deliveries start from.
		 *
		 * @return array{lat:float,lng:float}|null
		 */
		public static function origin(): ?array {
			$point = null;
			if ( class_exists( 'Lafka_Checkout_Fields' ) && Lafka_Checkout_Fields::is_branch_selection_active() ) {
				$point = self::branch_point();
			}
			if ( null === $point && function_exists( 'lafka_get_store_point' ) ) {
				$point = lafka_get_store_point();
			}

			/**
			 * Filter the point a distance-priced delivery starts from.
			 *
			 * @since 10.4.0
			 * @param array{lat:float,lng:float}|null $point Branch or store point.
			 */
			$filtered = apply_filters( 'lafka_distance_origin', $point );

			return is_array( $filtered ) ? lafka_geo_point( $filtered['lat'] ?? null, $filtered['lng'] ?? null ) : null;
		}

		/**
		 * The chosen branch's geocoded point (null without one).
		 *
		 * @return array{lat:float,lng:float}|null
		 */
		private static function branch_point(): ?array {
			$wc      = function_exists( 'WC' ) ? WC() : null;
			$session = ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;
			$branch  = null === $session ? null : $session->get( 'lafka_branch_location' );
			$id      = is_array( $branch ) ? (int) ( $branch['branch_id'] ?? 0 ) : 0;
			if ( $id <= 0 ) {
				return null;
			}

			return lafka_parse_store_map_location( (string) get_term_meta( $id, 'lafka_branch_address_geocoded', true ) );
		}

		/**
		 * A short identifier of a destination, for tying a pin to the address
		 * it was placed for (country, state, postcode, city, street; case and
		 * punctuation ignored).
		 *
		 * @param array $parts Keys country, state, postcode, city, address_1.
		 * @return string
		 */
		public static function fingerprint( array $parts ): string {
			$out = array();
			foreach ( array( 'country', 'state', 'postcode', 'city', 'address_1' ) as $key ) {
				$out[] = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', (string) ( $parts[ $key ] ?? '' ) ) );
			}

			return implode( '|', $out );
		}

		/**
		 * The address of a package destination as one geocodable line.
		 *
		 * @param array $destination WooCommerce package destination.
		 * @return string
		 */
		public static function address_line( array $destination ): string {
			$country = (string) ( $destination['country'] ?? '' );
			$state   = (string) ( $destination['state'] ?? '' );
			$wc      = function_exists( 'WC' ) ? WC() : null;
			if ( is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) ) {
				$states    = $wc->countries->get_states( $country );
				$countries = $wc->countries->get_countries();
				$state     = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state;
				$country   = $countries[ $country ] ?? $country;
			}
			$street = trim( (string) ( $destination['address_1'] ?? $destination['address'] ?? '' ) );
			$parts  = array( $street, (string) ( $destination['city'] ?? '' ), $state, (string) ( $destination['postcode'] ?? '' ), $country );

			return implode( ', ', array_filter( array_map( 'trim', $parts ), 'strlen' ) );
		}

		/**
		 * How far a delivery pin may sit from the geocoded address and still be
		 * trusted, in kilometres (default 1).
		 *
		 * @return float
		 */
		public static function pin_tolerance_km(): float {
			/**
			 * Filter how far (km) the customer's checkout pin may be from the
			 * server-side geocode of the typed address before the geocode prices
			 * the delivery instead. Stops a far address with a pin dropped beside
			 * the store from getting the cheapest band.
			 *
			 * @since 10.4.0
			 * @param float $km Default 1.0.
			 */
			return max( 0.0, (float) apply_filters( 'lafka_distance_pin_tolerance_km', 1.0 ) );
		}

		/**
		 * Where the package goes. The customer's pin is only a refinement: it is
		 * used when it lies within pin_tolerance_km() of the geocoded address;
		 * further away, the geocode prices the delivery. When the address cannot
		 * be geocoded the pin alone is used and the source says so ('pin_only'),
		 * so staff can see the address was not verified.
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return array{point:array{lat:float,lng:float},source:string}|WP_Error Source 'pin', 'pin_only' or 'geocode'.
		 */
		public static function destination( array $package ) {
			$destination = (array) ( $package['destination'] ?? array() );
			$geocoded    = self::geocode( $destination );

			$pin   = $package[ self::PACKAGE_PIN ] ?? null;
			$point = null;
			if ( is_array( $pin ) && isset( $pin['fp'] ) && self::fingerprint( $destination ) === $pin['fp'] ) {
				$point = lafka_geo_point( $pin['lat'] ?? null, $pin['lng'] ?? null );
			}

			if ( null !== $point ) {
				if ( is_wp_error( $geocoded ) ) {
					return array(
						'point'  => $point,
						'source' => 'pin_only',
					);
				}
				if ( self::straight_km( $point, $geocoded ) <= self::pin_tolerance_km() ) {
					return array(
						'point'  => $point,
						'source' => 'pin',
					);
				}
			}
			if ( is_wp_error( $geocoded ) ) {
				return $geocoded;
			}

			return array(
				'point'  => $geocoded,
				'source' => 'geocode',
			);
		}

		/**
		 * The street-level point of a package destination, from the cached
		 * server-side geocode.
		 *
		 * @param array $destination WooCommerce package destination.
		 * @return array{lat:float,lng:float}|WP_Error
		 */
		private static function geocode( array $destination ) {
			$line = self::address_line( $destination );
			if ( '' === trim( (string) ( $destination['address_1'] ?? $destination['address'] ?? '' ) ) ) {
				return new WP_Error( 'no_street', 'The delivery address has no street line.' );
			}

			$failed_key = 'lafka_dist_fail_' . md5( $line );
			if ( false !== get_transient( $failed_key ) ) {
				return new WP_Error( 'geocode_unreachable', 'The address lookup failed a moment ago.' );
			}

			$found = Lafka_Geocoder::search( $line );
			if ( is_wp_error( $found ) ) {
				set_transient( $failed_key, 1, self::SHORT_CACHE );

				return new WP_Error( 'geocode_unreachable', 'The geocoder could not be reached.' );
			}
			if ( ! is_array( $found ) ) {
				return new WP_Error( 'geocode_no_match', 'The geocoder found no match for the address.' );
			}
			if ( empty( $found['precise'] ) ) {
				return new WP_Error( 'geocode_imprecise', 'The geocoder only matched an area, not a street.' );
			}
			$country = strtoupper( (string) ( $destination['country'] ?? '' ) );
			$matched = strtoupper( (string) ( $found['address']['country'] ?? '' ) );
			if ( '' !== $country && '' !== $matched && $country !== $matched ) {
				return new WP_Error( 'geocode_wrong_country', 'The geocoder matched another country.' );
			}
			$point = lafka_geo_point( $found['lat'] ?? null, $found['lng'] ?? null );
			if ( null === $point ) {
				return new WP_Error( 'geocode_no_match', 'The geocoder answered without a valid point.' );
			}

			return $point;
		}

		/**
		 * Great-circle distance in kilometres (haversine).
		 *
		 * @param array{lat:float,lng:float} $from From.
		 * @param array{lat:float,lng:float} $to   To.
		 * @return float
		 */
		public static function straight_km( array $from, array $to ): float {
			$radius = 6371.0088;
			$lat1   = deg2rad( $from['lat'] );
			$lat2   = deg2rad( $to['lat'] );
			$dlat   = $lat2 - $lat1;
			$dlng   = deg2rad( $to['lng'] - $from['lng'] );
			$a      = sin( $dlat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $dlng / 2 ) ** 2;

			return 2 * $radius * asin( min( 1.0, sqrt( $a ) ) );
		}

		/**
		 * Which router answers a "driving distance" ('' = none available).
		 *
		 * @return string 'filter', 'osrm', 'google' or ''.
		 */
		public static function driving_provider(): string {
			if ( has_filter( 'lafka_distance_driving_meters' ) ) {
				return 'filter';
			}
			if ( '' !== self::osrm_endpoint() ) {
				return 'osrm';
			}

			return '' !== lafka_google_maps_key() ? 'google' : '';
		}

		/**
		 * The OSRM server base URL ('' = not used).
		 *
		 * @return string
		 */
		public static function osrm_endpoint(): string {
			/**
			 * Filter the OSRM server road distances come from (for example
			 * https://router.project-osrm.org or your own). Empty = not used.
			 *
			 * @since 10.4.0
			 * @param string $endpoint Base URL without a trailing slash; `/route/v1/driving/…` is appended.
			 */
			return untrailingslashit( (string) apply_filters( 'lafka_distance_osrm_endpoint', '' ) );
		}

		/**
		 * The distance of a delivery in kilometres.
		 *
		 * @param array{lat:float,lng:float} $from   Origin.
		 * @param array{lat:float,lng:float} $to     Destination.
		 * @param string                     $mode   'straight' or 'driving'.
		 * @param float                      $factor Road factor for 'straight'.
		 * @return float|WP_Error
		 */
		public static function distance_km( array $from, array $to, string $mode, float $factor ) {
			if ( 'driving' !== $mode ) {
				return self::straight_km( $from, $to ) * max( 1.0, $factor );
			}

			$provider = self::driving_provider();
			if ( '' === $provider ) {
				return new WP_Error( 'driving_unavailable', 'Driving distance needs a Google Maps key or an OSRM server, and neither is set.' );
			}
			$key    = 'lafka_dist_route_' . md5( $provider . '|' . round( $from['lat'], 5 ) . ',' . round( $from['lng'], 5 ) . '|' . round( $to['lat'], 5 ) . ',' . round( $to['lng'], 5 ) );
			$cached = get_transient( $key );
			if ( is_numeric( $cached ) ) {
				return (float) $cached / 1000;
			}

			$meters = 'filter' === $provider ? apply_filters( 'lafka_distance_driving_meters', null, $from, $to ) : ( 'osrm' === $provider ? self::osrm_meters( $from, $to ) : self::google_meters( $from, $to ) );
			if ( is_wp_error( $meters ) ) {
				return $meters;
			}
			if ( ! is_numeric( $meters ) || (float) $meters <= 0 ) {
				return new WP_Error( 'route_failed', 'The router returned no road distance.' );
			}
			// Short-lived on purpose: it only has to carry a checkout through its refreshes.
			set_transient( $key, (float) $meters, self::SHORT_CACHE );

			return (float) $meters / 1000;
		}

		/**
		 * Road metres from an OSRM server.
		 *
		 * @param array $from Origin.
		 * @param array $to   Destination.
		 * @return float|WP_Error
		 */
		private static function osrm_meters( array $from, array $to ) {
			$url      = self::osrm_endpoint() . '/route/v1/driving/' . $from['lng'] . ',' . $from['lat'] . ';' . $to['lng'] . ',' . $to['lat'] . '?overview=false';
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 8,
					'user-agent' => Lafka_Geocoder::user_agent(),
				)
			);

			return self::meters_from(
				$response,
				static function ( $body ) {
					return $body['routes'][0]['distance'] ?? null;
				}
			);
		}

		/**
		 * Road metres from Google's Routes API (needs the one Maps key).
		 *
		 * @param array $from Origin.
		 * @param array $to   Destination.
		 * @return float|WP_Error
		 */
		private static function google_meters( array $from, array $to ) {
			/**
			 * Filter Google's Routes endpoint.
			 *
			 * @since 10.4.0
			 * @param string $endpoint Default https://routes.googleapis.com/directions/v2:computeRoutes.
			 */
			$endpoint = (string) apply_filters( 'lafka_distance_google_endpoint', 'https://routes.googleapis.com/directions/v2:computeRoutes' );
			$waypoint = static function ( array $point ): array {
				return array(
					'location' => array(
						'latLng' => array(
							'latitude'  => $point['lat'],
							'longitude' => $point['lng'],
						),
					),
				);
			};
			// The key travels in a header, never in a URL that could reach a log.
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 8,
					'headers' => array(
						'Content-Type'     => 'application/json',
						'X-Goog-Api-Key'   => lafka_google_maps_key(),
						'X-Goog-FieldMask' => 'routes.distanceMeters',
					),
					'body'    => wp_json_encode(
						array(
							'origin'      => $waypoint( $from ),
							'destination' => $waypoint( $to ),
							'travelMode'  => 'DRIVE',
						)
					),
				)
			);

			return self::meters_from(
				$response,
				static function ( $body ) {
					return $body['routes'][0]['distanceMeters'] ?? null;
				}
			);
		}

		/**
		 * Read a router response.
		 *
		 * @param array|WP_Error $response HTTP response.
		 * @param callable       $pick     Takes the decoded body, returns metres or null.
		 * @return float|WP_Error
		 */
		private static function meters_from( $response, callable $pick ) {
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'route_unreachable', 'The router could not be reached.' );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error( 'route_failed', 'The router answered HTTP ' . $code . '.' );
			}
			$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$meters = is_array( $body ) ? $pick( $body ) : null;

			return is_numeric( $meters ) ? (float) $meters : new WP_Error( 'route_failed', 'The router found no route.' );
		}
	}
}
