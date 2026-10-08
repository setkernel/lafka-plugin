<?php
/**
 * Lafka_Geocoder — server-side geocoding for the keyless map provider.
 *
 * Without a Google Maps key the maps geocode through this proxy, which asks a
 * Nominatim server (https://nominatim.openstreetmap.org by default; point the
 * `lafka_geocoder_endpoint` filter at your own instance or another
 * Nominatim-compatible service). It follows the public server's usage policy
 * (https://operations.osmfoundation.org/policies/nominatim/):
 *
 *   - an identifying User-Agent (plugin, site URL, contact email —
 *     `lafka_geocoder_user_agent` / `lafka_geocoder_contact_email`);
 *   - at most one request per second from this site;
 *   - every answer cached (30 days, misses 1 day), so a repeated address
 *     never reaches the server again;
 *   - no search-as-you-type: the customer submits an address (or shares a
 *     location) and gets one lookup.
 *
 * Routes (namespace lafka/v1):
 *   GET /admin/geocode?q=…  |  ?lat=…&lng=…   shop managers (admin maps)
 *   GET /geocode?q=…        |  ?lat=…&lng=…   customers (branch modal, checkout
 *                                             pin), only with the Delivery areas
 *                                             module on; same-origin, rate
 *                                             limited per visitor
 *
 * Both answer { result: {lat, lng, label, precise, level, address{…}, regions[…]} | null }.
 *
 * @package Lafka\Plugin\Geo
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Geocoder' ) ) {

	/**
	 * Nominatim geocoding proxy with caching, politeness and rate limits.
	 */
	final class Lafka_Geocoder {

		/** REST namespace. */
		const REST_NAMESPACE = 'lafka/v1';

		/** Transient prefix for cached answers. */
		const CACHE_PREFIX = 'lafka_geo_';

		/** Option holding the time of the last upstream request (politeness). */
		const LAST_REQUEST_OPTION = 'lafka_geocoder_last_request';

		/**
		 * Hook the REST routes.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Register the admin and the customer route.
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			$args = array(
				'q'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'lat' => array(
					'type'     => 'number',
					'required' => false,
				),
				'lng' => array(
					'type'     => 'number',
					'required' => false,
				),
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/admin/geocode',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_admin' ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_woocommerce' );
					},
					'args'                => $args,
				)
			);
			if ( function_exists( 'is_lafka_shipping_areas' ) && is_lafka_shipping_areas() ) {
				register_rest_route(
					self::REST_NAMESPACE,
					'/geocode',
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'handle_public' ),
						'permission_callback' => '__return_true',
						'args'                => $args,
					)
				);
			}
		}

		/**
		 * GET /admin/geocode.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function handle_admin( $request ) {
			return self::respond( $request );
		}

		/**
		 * GET /geocode: same-origin and rate limited per visitor.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function handle_public( $request ) {
			if ( ! class_exists( 'Lafka_Beacon_Guard' ) ) {
				require_once dirname( __DIR__ ) . '/class-lafka-beacon-guard.php';
			}
			if ( ! Lafka_Beacon_Guard::is_same_origin( $request ) ) {
				return new WP_Error( 'lafka_geocode_origin', __( 'Address lookup is only available on this site.', 'lafka-plugin' ), array( 'status' => 403 ) );
			}
			if ( Lafka_Beacon_Guard::rate_limited( 'geocode', self::visitor_key(), 20, 600, HOUR_IN_SECONDS ) ) {
				return new WP_Error( 'lafka_geocode_rate_limited', __( 'Too many address lookups. Please place the pin on the map instead.', 'lafka-plugin' ), array( 'status' => 429 ) );
			}

			return self::respond( $request );
		}

		/**
		 * Run a search (q) or a reverse lookup (lat + lng) for a request.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		private static function respond( $request ) {
			$query = trim( (string) $request->get_param( 'q' ) );
			$lat   = $request->get_param( 'lat' );
			$lng   = $request->get_param( 'lng' );

			if ( '' !== $query ) {
				$result = self::search( $query );
			} elseif ( null !== $lat && null !== $lng && null !== lafka_geo_point( $lat, $lng ) ) {
				$result = self::reverse( (float) $lat, (float) $lng );
			} else {
				return new WP_Error( 'lafka_geocode_input', __( 'Enter an address, or a latitude and longitude.', 'lafka-plugin' ), array( 'status' => 400 ) );
			}

			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 502 ) );
				return $result;
			}

			return new WP_REST_Response( array( 'result' => $result ), 200 );
		}

		/**
		 * A per-visitor rate-limit key the visitor cannot rotate: the account
		 * when logged in, else the connection IP (Cloudflare's client IP only
		 * when the request really came through Cloudflare). Never a cookie,
		 * browser string or forwarded-for header, which a client can change on
		 * every request to get a fresh allowance. Hashed by the limiter.
		 *
		 * @return string
		 */
		public static function visitor_key(): string {
			$user = get_current_user_id();
			if ( $user > 0 ) {
				return 'u' . $user;
			}
			if ( ! class_exists( 'Lafka_Insights_Session' ) ) {
				require_once dirname( __DIR__ ) . '/insights/class-lafka-insights-session.php';
			}

			return 'i' . Lafka_Insights_Session::client_ip();
		}

		/**
		 * Geocode an address: a normalised result, null when nothing matched, or
		 * a WP_Error when the geocoder could not be reached.
		 *
		 * @param string $query Address.
		 * @return array|null|WP_Error
		 */
		public static function search( string $query ) {
			$query = trim( preg_replace( '/\s+/', ' ', $query ) );
			if ( '' === $query || strlen( $query ) > 300 ) {
				return null;
			}
			$params = array(
				'q'      => $query,
				'format' => 'jsonv2',
				'limit'  => 1,
			);

			return self::lookup( 'search', $params );
		}

		/**
		 * Reverse-geocode a point: the nearest address, null when none, or a
		 * WP_Error when the geocoder could not be reached.
		 *
		 * @param float $lat Latitude.
		 * @param float $lng Longitude.
		 * @return array|null|WP_Error
		 */
		public static function reverse( float $lat, float $lng ) {
			$params = array(
				'lat'    => (string) round( $lat, 6 ),
				'lon'    => (string) round( $lng, 6 ),
				'format' => 'jsonv2',
			);

			return self::lookup( 'reverse', $params );
		}

		/**
		 * A cached search answer without ever asking the server (null when the
		 * address was never looked up or matched nothing).
		 *
		 * @param string $query Address.
		 * @return array|null
		 */
		public static function cached_search( string $query ): ?array {
			$query = trim( preg_replace( '/\s+/', ' ', $query ) );
			if ( '' === $query ) {
				return null;
			}
			$cached = get_transient(
				self::cache_key(
					'search',
					array(
						'q'      => $query,
						'format' => 'jsonv2',
						'limit'  => 1,
					)
				)
			);

			return is_array( $cached ) && isset( $cached['result'] ) && is_array( $cached['result'] ) ? $cached['result'] : null;
		}

		/**
		 * The geocoder base URL (no trailing slash).
		 *
		 * @return string
		 */
		public static function endpoint(): string {
			/**
			 * Filter the Nominatim-compatible geocoder the keyless maps use.
			 *
			 * @since 10.4.0
			 * @param string $endpoint Base URL; `/search` and `/reverse` are appended.
			 */
			return untrailingslashit( (string) apply_filters( 'lafka_geocoder_endpoint', 'https://nominatim.openstreetmap.org' ) );
		}

		/**
		 * The identifying User-Agent the usage policy asks for.
		 *
		 * @return string
		 */
		public static function user_agent(): string {
			/**
			 * Filter the contact email sent to the geocoder in the User-Agent.
			 *
			 * @since 10.4.0
			 * @param string $email Contact email (defaults to the site admin email).
			 */
			$email   = sanitize_email( (string) apply_filters( 'lafka_geocoder_contact_email', (string) get_option( 'admin_email', '' ) ) );
			$version = defined( 'LAFKA_PLUGIN_VERSION' ) ? LAFKA_PLUGIN_VERSION : '';
			$agent   = trim( 'Lafka-Plugin/' . $version ) . ' (' . home_url( '/' ) . ( '' === $email ? '' : '; ' . $email ) . ')';

			/**
			 * Filter the User-Agent sent to the geocoder.
			 *
			 * @since 10.4.0
			 * @param string $agent User-Agent.
			 */
			return (string) apply_filters( 'lafka_geocoder_user_agent', $agent );
		}

		/**
		 * Cache key for a request.
		 *
		 * @param string $type   'search' or 'reverse'.
		 * @param array  $params Query parameters.
		 * @return string
		 */
		private static function cache_key( string $type, array $params ): string {
			return self::CACHE_PREFIX . md5( self::endpoint() . '|' . $type . '|' . wp_json_encode( $params ) . '|' . self::language() );
		}

		/**
		 * The answer language: the site locale.
		 *
		 * @return string
		 */
		private static function language(): string {
			return str_replace( '_', '-', (string) get_locale() );
		}

		/**
		 * Cached lookup against the geocoder.
		 *
		 * @param string $type   'search' or 'reverse'.
		 * @param array  $params Query parameters.
		 * @return array|null|WP_Error
		 */
		private static function lookup( string $type, array $params ) {
			$key    = self::cache_key( $type, $params );
			$cached = get_transient( $key );
			if ( is_array( $cached ) && array_key_exists( 'result', $cached ) ) {
				return $cached['result'];
			}

			self::wait_for_slot();
			$query    = array_merge(
				$params,
				array(
					'addressdetails'  => 1,
					'accept-language' => self::language(),
				)
			);
			$url      = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), self::endpoint() . '/' . $type );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 8,
					'user-agent' => self::user_agent(),
					'headers'    => array( 'Referer' => home_url( '/' ) ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'lafka_geocode_unreachable', __( 'The address lookup service could not be reached. Please place the pin on the map.', 'lafka-plugin' ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error( 'lafka_geocode_failed', __( 'The address lookup service is busy. Please place the pin on the map.', 'lafka-plugin' ) );
			}

			$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$place  = 'search' === $type ? ( is_array( $body ) && isset( $body[0] ) ? $body[0] : null ) : $body;
			$result = is_array( $place ) && ! isset( $place['error'] ) ? self::normalise( $place ) : null;

			set_transient( $key, array( 'result' => $result ), null === $result ? DAY_IN_SECONDS : 30 * DAY_IN_SECONDS );

			return $result;
		}

		/**
		 * Keep this site to one upstream request per second.
		 *
		 * @return void
		 */
		private static function wait_for_slot(): void {
			$last = (float) get_option( self::LAST_REQUEST_OPTION, 0 );
			$wait = 1.0 - ( microtime( true ) - $last );
			if ( $wait > 0 && $wait <= 1.0 ) {
				usleep( (int) ( $wait * 1000000 ) );
			}
			update_option( self::LAST_REQUEST_OPTION, (string) microtime( true ), false );
		}

		/**
		 * Normalise a Nominatim place into the shape the map scripts read (the
		 * same shape lafka-maps.js builds from a Google Geocoder result).
		 *
		 * @param array $place Nominatim jsonv2 place.
		 * @return array|null
		 */
		public static function normalise( array $place ): ?array {
			$point = lafka_geo_point( $place['lat'] ?? null, $place['lon'] ?? null );
			if ( null === $point ) {
				return null;
			}
			$address = isset( $place['address'] ) && is_array( $place['address'] ) ? $place['address'] : array();
			$pick    = static function ( array $keys ) use ( $address ): string {
				foreach ( $keys as $key ) {
					if ( isset( $address[ $key ] ) && '' !== trim( (string) $address[ $key ] ) ) {
						return trim( (string) $address[ $key ] );
					}
				}
				return '';
			};

			$street = $pick( array( 'road', 'pedestrian', 'footway', 'residential', 'path', 'square' ) );
			$number = $pick( array( 'house_number' ) );
			$iso    = $pick( array( 'ISO3166-2-lvl4', 'ISO3166-2-lvl6', 'ISO3166-2-lvl5' ) );
			$state  = '' !== $iso && false !== strpos( $iso, '-' ) ? substr( $iso, strpos( $iso, '-' ) + 1 ) : '';
			$rank   = isset( $place['place_rank'] ) ? (int) $place['place_rank'] : 0;

			return array(
				'lat'     => $point['lat'],
				'lng'     => $point['lng'],
				'label'   => (string) ( $place['display_name'] ?? '' ),
				// Street level or finer (Google's non-APPROXIMATE results).
				'precise' => '' !== $number || $rank >= 26,
				'level'   => ( '' !== $number || $rank >= 30 ) ? 'address' : ( $rank >= 26 ? 'street' : 'area' ),
				'address' => array(
					'address_1' => trim( $number . ' ' . $street ),
					'city'      => $pick( array( 'city', 'town', 'village', 'hamlet', 'municipality', 'suburb' ) ),
					'state'     => $state,
					'postcode'  => $pick( array( 'postcode' ) ),
					'country'   => strtoupper( $pick( array( 'country_code' ) ) ),
				),
				'regions' => array(
					array(
						'short' => $state,
						'long'  => $pick( array( 'state', 'province', 'region' ) ),
					),
					array(
						'short' => '',
						'long'  => $pick( array( 'county', 'state_district' ) ),
					),
					array(
						'short' => '',
						'long'  => $pick( array( 'municipality', 'city_district' ) ),
					),
				),
			);
		}
	}

	Lafka_Geocoder::init();
}
