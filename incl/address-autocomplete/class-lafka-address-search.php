<?php
/**
 * Lafka_Address_Search: the server side of checkout address autocomplete.
 *
 * The browser never talks to a map service: it asks this class's REST routes,
 * which ask one backend and answer in WooCommerce's address shape. Which
 * backend is decided here, once:
 *
 *   - Google Places (New) when the one Maps key (lafka_google_maps_key()) is
 *     set. The key travels in a header, with the site as Referer so a
 *     referrer-restricted browser key works from the server too.
 *   - Photon (https://photon.komoot.io, OpenStreetMap data; endpoint
 *     filterable) otherwise. Nominatim's usage policy forbids search-as-you-type,
 *     Photon is built for it. Results are biased to the store point and cut
 *     to the customer's country.
 *
 * Politeness and privacy: every answer is cached, each visitor and the site
 * as a whole are rate limited (Lafka_Beacon_Guard), requests need a same-site
 * Origin or Referer, and the typed text is sent only to the chosen backend.
 *
 * Routes (namespace lafka/v1), both GET, only while WooCommerce's address
 * autocomplete is on and the module is enabled:
 *   /address/suggest?q=…&country=CA&session=…   { suggestions: [ {id, label} ] }
 *   /address/place?id=…&session=…               { place: {address_1, address_2, city, state, postcode, country, lat, lng} }
 *
 * @package Lafka\Plugin\AddressAutocomplete
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Address_Search' ) ) {

	/**
	 * Suggestions and places from Google Places (New) or Photon.
	 */
	final class Lafka_Address_Search {

		/** REST namespace (shared with the geocoder). */
		const REST_NAMESPACE = 'lafka/v1';

		/** Fewest characters that are searched. */
		const MIN_CHARS = 4;

		/** Most suggestions returned. */
		const MAX_RESULTS = 5;

		/** Transient prefix for cached suggestions and chosen places. */
		const CACHE_PREFIX = 'lafka_ac_';

		/** Countries that write the house number after the street name. */
		const NUMBER_AFTER_STREET = array( 'DE', 'AT', 'CH', 'NL', 'BE', 'ES', 'IT', 'PT', 'SE', 'NO', 'DK', 'FI', 'PL', 'CZ', 'SK', 'HU', 'GR', 'TR', 'BR', 'AR', 'CL', 'CO', 'HR', 'SI', 'RS', 'RO', 'BG', 'IS' );

		/**
		 * Hook the REST routes.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Whether the module is on (the registry's switch; default on).
		 *
		 * @return bool
		 */
		public static function module_enabled(): bool {
			return '1' === (string) lafka_setting( 'lafka_address_autocomplete_enabled', '1' );
		}

		/**
		 * Whether WooCommerce's own address autocomplete is switched on.
		 *
		 * @return bool
		 */
		public static function woocommerce_enabled(): bool {
			return function_exists( 'wc_string_to_bool' ) && wc_string_to_bool( get_option( 'woocommerce_address_autocomplete_enabled', 'no' ) );
		}

		/**
		 * Which backend answers: 'google' with the Maps key while today's paid
		 * budget lasts, else 'photon'.
		 *
		 * @return string
		 */
		public static function backend(): string {
			return '' !== lafka_google_maps_key() && self::google_budget_left() ? 'google' : 'photon';
		}

		/**
		 * Most Google autocomplete sessions (one billed search each) per day.
		 * 0 turns the paid service off.
		 *
		 * @return int
		 */
		public static function google_daily_budget(): int {
			/**
			 * Filter the daily budget of paid Google Places sessions. A session is
			 * one address search from first keystroke to the chosen suggestion.
			 * After it is used up, suggestions come from Photon until midnight (site time).
			 *
			 * @since 10.4.0
			 * @param int $sessions Default: the `lafka_address_google_daily_sessions` setting, else 500.
			 */
			return max( 0, (int) apply_filters( 'lafka_address_google_daily_budget', (int) lafka_setting( 'lafka_address_google_daily_sessions', 500 ) ) );
		}

		/**
		 * Cache key of today's count of paid sessions.
		 *
		 * @return string
		 */
		private static function budget_key(): string {
			return self::CACHE_PREFIX . 'day_' . wp_date( 'Ymd' );
		}

		/**
		 * Whether today's paid budget is not used up yet.
		 *
		 * @return bool
		 */
		public static function google_budget_left(): bool {
			return (int) get_transient( self::budget_key() ) < self::google_daily_budget();
		}

		/**
		 * Decide whether a Google search session may go on, counting it once
		 * against the daily budget the first time its token is seen.
		 *
		 * @param string $session Session token from the browser.
		 * @param string $step    'suggest' or 'place'.
		 * @return string 'ok', 'none' (no usable token or a place for an unknown session), 'budget' (used up) or 'cap' (this session searched enough).
		 */
		private static function google_session( string $session, string $step ): string {
			if ( 1 !== preg_match( '/^[A-Za-z0-9-]{16,64}$/', $session ) ) {
				return 'none';
			}
			$key   = self::CACHE_PREFIX . 'sess_' . md5( $session );
			$state = get_transient( $key );
			if ( ! is_array( $state ) ) {
				if ( 'suggest' !== $step ) {
					return 'none';
				}
				$state = array(
					'suggest' => 0,
					'place'   => 0,
					'counted' => false,
				);
			}
			// A session is counted against the budget at its first paid call, and only while budget is left.
			if ( empty( $state['counted'] ) ) {
				if ( ! self::google_budget_left() ) {
					self::log_budget();

					return 'budget';
				}
				set_transient( self::budget_key(), (int) get_transient( self::budget_key() ) + 1, 2 * DAY_IN_SECONDS );
				$state['counted'] = true;
			}
			/**
			 * Filter how many suggestion searches one Google session may make.
			 *
			 * @since 10.4.0
			 * @param int $max Default 15.
			 */
			$limit = 'suggest' === $step ? (int) apply_filters( 'lafka_address_google_session_searches', 15 ) : 3;
			if ( (int) $state[ $step ] >= $limit ) {
				return 'cap';
			}
			++$state[ $step ];
			set_transient( $key, $state, 30 * MINUTE_IN_SECONDS );

			return 'ok';
		}

		/**
		 * Whether a session was already counted against the budget (so it may
		 * finish on Google although the budget is used up since).
		 *
		 * @param string $session Session token from the browser.
		 * @return bool
		 */
		private static function session_counted( string $session ): bool {
			$state = get_transient( self::CACHE_PREFIX . 'sess_' . md5( $session ) );

			return is_array( $state ) && ! empty( $state['counted'] );
		}

		/**
		 * Let a search session that was answered from the cache go on to its
		 * place lookup. It is not counted against the daily budget yet: its
		 * first paid call counts it (and is refused when the budget is used up).
		 *
		 * @param string $session Session token from the browser.
		 * @return void
		 */
		private static function remember_session( string $session ): void {
			if ( 1 !== preg_match( '/^[A-Za-z0-9-]{16,64}$/', $session ) ) {
				return;
			}
			$key = self::CACHE_PREFIX . 'sess_' . md5( $session );
			if ( false === get_transient( $key ) ) {
				set_transient(
					$key,
					array(
						'suggest' => 1,
						'place'   => 0,
						'counted' => false,
					),
					30 * MINUTE_IN_SECONDS
				);
			}
		}

		/**
		 * Log, once a day, that the paid budget is used up.
		 *
		 * @return void
		 */
		private static function log_budget(): void {
			$flag = self::CACHE_PREFIX . 'budget_logged_' . wp_date( 'Ymd' );
			if ( false === get_transient( $flag ) ) {
				set_transient( $flag, 1, DAY_IN_SECONDS );
				self::log( 'budget_used_up', 'The daily budget of ' . self::google_daily_budget() . ' Google address sessions is used up; Photon answers until midnight.' );
			}
		}

		/**
		 * The page token that lets a checkout visitor use the routes. It binds
		 * to the WooCommerce customer session (a session cookie exists once
		 * the cart holds something) and is only printed on the checkout page.
		 *
		 * @param int $back How many 12-hour periods back (0 = now).
		 * @return string '' without a customer session.
		 */
		public static function page_token( int $back = 0 ): string {
			$wc = function_exists( 'WC' ) ? WC() : null;
			$id = ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? (string) $wc->session->get_customer_id() : '';
			if ( '' === $id ) {
				return '';
			}

			return substr( wp_hash( 'lafka_address|' . $id . '|' . ( (int) floor( time() / ( 12 * HOUR_IN_SECONDS ) ) - $back ), 'nonce' ), 0, 24 );
		}

		/**
		 * Whether a request carries a valid page token and belongs to a
		 * shopper with something in the cart.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return string '' when fine, else the reason ('token' or 'session').
		 */
		private static function refuse_reason( $request ): string {
			if ( function_exists( 'wc_load_cart' ) && ( ! isset( WC()->session ) || ! isset( WC()->cart ) || ! WC()->cart ) ) {
				wc_load_cart();
			}
			$given = (string) $request->get_header( 'x_lafka_address_token' );
			if ( '' === $given || ( ! hash_equals( self::page_token( 0 ), $given ) && ! hash_equals( self::page_token( 1 ), $given ) ) || '' === self::page_token( 0 ) ) {
				return 'token';
			}
			$cart = isset( WC()->cart ) ? WC()->cart : null;

			return ( is_object( $cart ) && ! $cart->is_empty() ) ? '' : 'session';
		}

		/**
		 * The countries that can be searched: the ones the shop sells to.
		 *
		 * @return string[] Upper-case ISO codes.
		 */
		public static function countries(): array {
			$wc    = function_exists( 'WC' ) ? WC() : null;
			$codes = ( is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) ) ? array_keys( (array) $wc->countries->get_allowed_countries() ) : array();

			/**
			 * Filter the countries address autocomplete searches.
			 *
			 * @since 10.4.0
			 * @param string[] $codes ISO country codes (default: the countries the shop sells to).
			 */
			$codes = (array) apply_filters( 'lafka_address_autocomplete_countries', $codes );

			return array_values( array_unique( array_map( 'strtoupper', array_filter( array_map( 'strval', $codes ) ) ) ) );
		}

		/* ------------------------------------------------------------------ *
		 *  REST
		 * ------------------------------------------------------------------ */

		/**
		 * Register the two customer routes.
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			$common = array(
				'country' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => static function ( $value ) {
						return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
					},
				),
				'session' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => static function ( $value ) {
						return substr( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $value ), 0, 64 );
					},
				),
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/address/suggest',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_suggest' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$common,
						array(
							'q' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
						)
					),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/address/place',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_place' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$common,
						array(
							'id' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => static function ( $value ) {
									return substr( preg_replace( '/[^A-Za-z0-9_:-]/', '', (string) $value ), 0, 300 );
								},
							),
						)
					),
				)
			);
		}

		/**
		 * Gate shared by both routes: on, same-site, within the rate limit.
		 *
		 * @param WP_REST_Request $request       Request.
		 * @param bool            $needs_country Whether the request must name a country the shop sells to.
		 * @return WP_Error|null Null when the request may go on.
		 */
		private static function gate( $request, bool $needs_country ) {
			if ( ! self::module_enabled() || ! self::woocommerce_enabled() ) {
				return new WP_Error( 'lafka_address_off', __( 'Address suggestions are switched off.', 'lafka-plugin' ), array( 'status' => 404 ) );
			}
			if ( ! class_exists( 'Lafka_Beacon_Guard' ) ) {
				require_once dirname( __DIR__ ) . '/class-lafka-beacon-guard.php';
			}
			if ( ! Lafka_Beacon_Guard::is_same_origin( $request ) ) {
				return new WP_Error( 'lafka_address_origin', __( 'Address suggestions are only available on this site.', 'lafka-plugin' ), array( 'status' => 403 ) );
			}
			// The origin header is only a hint (scripts can send anything): the page token proves the visitor loaded the checkout with items in the cart.
			if ( '' !== self::refuse_reason( $request ) ) {
				return new WP_Error( 'lafka_address_session', __( 'Address suggestions are only available while you check out.', 'lafka-plugin' ), array( 'status' => 403 ) );
			}
			if ( Lafka_Beacon_Guard::rate_limited( 'address', Lafka_Geocoder::visitor_key(), 90, 1000, 10 * MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'lafka_address_rate_limited', __( 'Too many address lookups. Please type the address in full.', 'lafka-plugin' ), array( 'status' => 429 ) );
			}
			if ( $needs_country && ! in_array( (string) $request->get_param( 'country' ), self::countries(), true ) ) {
				return new WP_Error( 'lafka_address_country', __( 'We do not search addresses in that country.', 'lafka-plugin' ), array( 'status' => 400 ) );
			}

			return null;
		}

		/**
		 * GET /address/suggest.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function handle_suggest( $request ) {
			$blocked = self::gate( $request, true );
			if ( null !== $blocked ) {
				return $blocked;
			}
			$found = self::suggest( (string) $request->get_param( 'q' ), (string) $request->get_param( 'country' ), (string) $request->get_param( 'session' ) );
			if ( is_wp_error( $found ) ) {
				$found->add_data( array( 'status' => 502 ) );

				return $found;
			}

			return new WP_REST_Response( array( 'suggestions' => $found ), 200 );
		}

		/**
		 * GET /address/place.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function handle_place( $request ) {
			$blocked = self::gate( $request, false );
			if ( null !== $blocked ) {
				return $blocked;
			}
			$place = self::place( (string) $request->get_param( 'id' ), (string) $request->get_param( 'session' ) );
			if ( is_wp_error( $place ) ) {
				$place->add_data( array( 'status' => 502 ) );

				return $place;
			}

			return new WP_REST_Response( array( 'place' => $place ), 200 );
		}

		/* ------------------------------------------------------------------ *
		 *  Suggestions
		 * ------------------------------------------------------------------ */

		/**
		 * Address suggestions for what the customer has typed.
		 *
		 * @param string $query   Typed text.
		 * @param string $country ISO country code.
		 * @param string $session Autocomplete session token (Google billing session).
		 * @return array<int,array{id:string,label:string}>|WP_Error
		 */
		public static function suggest( string $query, string $country, string $session = '' ) {
			$query = trim( (string) preg_replace( '/\s+/', ' ', $query ) );
			if ( strlen( $query ) < self::MIN_CHARS || strlen( $query ) > 120 ) {
				return array();
			}
			if ( ! class_exists( 'Lafka_Beacon_Guard' ) ) {
				require_once dirname( __DIR__ ) . '/class-lafka-beacon-guard.php';
			}
			$backend = self::backend();
			if ( 'photon' === $backend && '' !== lafka_google_maps_key() && ! self::google_budget_left() ) {
				self::log_budget();
			}
			// A search already counted against the budget finishes on Google.
			if ( 'photon' === $backend && '' !== lafka_google_maps_key() && self::session_counted( $session ) ) {
				$backend = 'google';
			}
			$point  = function_exists( 'lafka_get_store_point' ) ? lafka_get_store_point() : null;
			$key    = static function ( string $which ) use ( $query, $country, $point ): string {
				return self::CACHE_PREFIX . 's_' . md5( $which . '|' . strtolower( $query ) . '|' . $country . '|' . wp_json_encode( $point ) . '|' . self::language() );
			};
			$cached = get_transient( $key( $backend ) );
			if ( is_array( $cached ) ) {
				if ( 'google' === $backend ) {
					// No paid call was made, but the customer may still choose one of these places.
					self::remember_session( $session );
				}

				return $cached;
			}

			if ( 'google' === $backend ) {
				// Paid path: a low site-wide pace, and one counted session per search.
				if ( Lafka_Beacon_Guard::rate_limited( 'address_paid', 'site', 0, 30, 60 ) ) {
					$backend = 'photon';
				} else {
					$step = self::google_session( $session, 'suggest' );
					if ( 'cap' === $step ) {
						return array();
					}
					$backend = 'ok' === $step ? 'google' : 'photon';
				}
				$cached = 'photon' === $backend ? get_transient( $key( 'photon' ) ) : false;
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}

			$found = 'google' === $backend ? self::google_suggest( $query, $country, $session, $point ) : self::photon_suggest( $query, $country, $point );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			set_transient( $key( $backend ), $found, 6 * HOUR_IN_SECONDS );

			return $found;
		}

		/**
		 * One chosen suggestion as a WooCommerce address with its point.
		 *
		 * @param string $id      Suggestion id.
		 * @param string $session Autocomplete session token.
		 * @return array|WP_Error
		 */
		public static function place( string $id, string $session = '' ) {
			if ( '' === $id ) {
				return new WP_Error( 'lafka_address_id', __( 'That address could not be found.', 'lafka-plugin' ) );
			}
			if ( 0 === strpos( $id, 'p:' ) ) {
				$place = get_transient( self::CACHE_PREFIX . 'p_' . substr( $id, 2 ) );

				return is_array( $place ) ? $place : new WP_Error( 'lafka_address_expired', __( 'That suggestion has expired. Please search again.', 'lafka-plugin' ) );
			}

			$key    = self::CACHE_PREFIX . 'g_' . md5( $id . '|' . self::language() );
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// A paid details call only closes a search session this site knows, and shares the paid path's site-wide pace.
			if ( ! class_exists( 'Lafka_Beacon_Guard' ) ) {
				require_once dirname( __DIR__ ) . '/class-lafka-beacon-guard.php';
			}
			if ( Lafka_Beacon_Guard::rate_limited( 'address_paid', 'site', 0, 30, 60 ) || 'ok' !== self::google_session( $session, 'place' ) ) {
				return new WP_Error( 'lafka_address_session', __( 'Please search for the address again.', 'lafka-plugin' ) );
			}
			$place = self::google_place( $id, $session );
			if ( is_wp_error( $place ) ) {
				return $place;
			}
			set_transient( $key, $place, HOUR_IN_SECONDS );

			return $place;
		}

		/* ------------------------------------------------------------------ *
		 *  Photon
		 * ------------------------------------------------------------------ */

		/**
		 * Photon base URL (no trailing slash).
		 *
		 * @return string
		 */
		public static function photon_endpoint(): string {
			/**
			 * Filter the Photon-compatible server keyless address search uses.
			 * The public instance is for fair use; run your own for heavy traffic.
			 *
			 * @since 10.4.0
			 * @param string $endpoint Base URL; `/api` is appended.
			 */
			return untrailingslashit( (string) apply_filters( 'lafka_address_photon_endpoint', 'https://photon.komoot.io' ) );
		}

		/**
		 * Search Photon.
		 *
		 * @param string                            $query   Typed text.
		 * @param string                            $country ISO country code.
		 * @param array{lat:float,lng:float}|null $point   Store point (bias).
		 * @return array<int,array{id:string,label:string}>|WP_Error
		 */
		private static function photon_suggest( string $query, string $country, ?array $point ) {
			$params = array(
				'q'     => $query,
				'limit' => 10,
			);
			// Photon answers only some languages (an unsupported one is an error).
			$lang = strtolower( substr( (string) get_locale(), 0, 2 ) );
			if ( in_array( $lang, array( 'en', 'de', 'fr' ), true ) ) {
				$params['lang'] = $lang;
			}
			if ( null !== $point ) {
				$params['lat'] = round( $point['lat'], 4 );
				$params['lon'] = round( $point['lng'], 4 );
			}

			/**
			 * Filter the query sent to Photon.
			 *
			 * @since 10.4.0
			 * @param array  $params  q, limit, lang, lat, lon.
			 * @param string $country ISO country code being searched.
			 */
			$params = (array) apply_filters( 'lafka_address_photon_params', $params, $country );
			// `layer` repeats, so the query string is built by hand: addresses and streets only.
			$url      = self::photon_endpoint() . '/api/?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) . '&layer=house&layer=street';
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 6,
					'user-agent' => Lafka_Geocoder::user_agent(),
					'headers'    => array( 'Referer' => home_url( '/' ) ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				self::log( 'photon_unavailable', is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );

				return new WP_Error( 'lafka_address_unavailable', __( 'Address suggestions are not available right now.', 'lafka-plugin' ) );
			}
			$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$features = is_array( $body ) && isset( $body['features'] ) && is_array( $body['features'] ) ? $body['features'] : array();
			$out      = array();
			$seen     = array();
			foreach ( $features as $feature ) {
				$place = is_array( $feature ) ? self::photon_place( $feature, $country ) : null;
				if ( null === $place ) {
					continue;
				}
				$label = self::label( $place );
				if ( isset( $seen[ $label ] ) ) {
					continue;
				}
				$seen[ $label ] = true;
				$id             = substr( sha1( $label . '|' . $place['lat'] . '|' . $place['lng'] ), 0, 24 );
				set_transient( self::CACHE_PREFIX . 'p_' . $id, $place, HOUR_IN_SECONDS );
				$out[] = array(
					'id'    => 'p:' . $id,
					'label' => $label,
				);
				if ( count( $out ) >= self::MAX_RESULTS ) {
					break;
				}
			}

			return $out;
		}

		/**
		 * A Photon GeoJSON feature as a WooCommerce address (null when it is
		 * in another country or has no street).
		 *
		 * @param array  $feature Feature.
		 * @param string $country ISO country code wanted.
		 * @return array|null
		 */
		public static function photon_place( array $feature, string $country ): ?array {
			$props = isset( $feature['properties'] ) && is_array( $feature['properties'] ) ? $feature['properties'] : array();
			$coord = isset( $feature['geometry']['coordinates'] ) && is_array( $feature['geometry']['coordinates'] ) ? $feature['geometry']['coordinates'] : array();
			$point = lafka_geo_point( $coord[1] ?? null, $coord[0] ?? null );
			$cc    = strtoupper( (string) ( $props['countrycode'] ?? '' ) );
			if ( null === $point || $cc !== $country ) {
				return null;
			}
			$street = trim( (string) ( $props['street'] ?? ( 'street' === ( $props['type'] ?? '' ) ? ( $props['name'] ?? '' ) : '' ) ) );
			if ( '' === $street ) {
				return null;
			}
			$city = '';
			foreach ( array( 'city', 'locality', 'district', 'county' ) as $part ) {
				if ( '' !== trim( (string) ( $props[ $part ] ?? '' ) ) ) {
					$city = trim( (string) $props[ $part ] );
					break;
				}
			}

			return array(
				'address_1' => self::street_line( $cc, trim( (string) ( $props['housenumber'] ?? '' ) ), $street ),
				'address_2' => '',
				'city'      => $city,
				'state'     => self::state_code( $cc, (string) ( $props['state'] ?? '' ) ),
				'postcode'  => trim( (string) ( $props['postcode'] ?? '' ) ),
				'country'   => $cc,
				'lat'       => round( $point['lat'], 6 ),
				'lng'       => round( $point['lng'], 6 ),
			);
		}

		/* ------------------------------------------------------------------ *
		 *  Google Places (New)
		 * ------------------------------------------------------------------ */

		/**
		 * Places API (New) base URL (no trailing slash).
		 *
		 * @return string
		 */
		private static function google_endpoint(): string {
			/**
			 * Filter the Places API (New) endpoint.
			 *
			 * @since 10.4.0
			 * @param string $endpoint Default https://places.googleapis.com/v1.
			 */
			return untrailingslashit( (string) apply_filters( 'lafka_address_google_endpoint', 'https://places.googleapis.com/v1' ) );
		}

		/**
		 * Headers every Places request carries. The key goes in a header, never
		 * in a URL that could reach a log; the site is the Referer so a
		 * referrer-restricted key is accepted from the server.
		 *
		 * @param string $field_mask Optional response field mask.
		 * @return array<string,string>
		 */
		private static function google_headers( string $field_mask = '' ): array {
			$headers = array(
				'Content-Type'   => 'application/json',
				'X-Goog-Api-Key' => lafka_google_maps_key(),
				'Referer'        => home_url( '/' ),
			);
			if ( '' !== $field_mask ) {
				$headers['X-Goog-FieldMask'] = $field_mask;
			}

			return $headers;
		}

		/**
		 * Places Autocomplete (New).
		 *
		 * @param string                            $query   Typed text.
		 * @param string                            $country ISO country code.
		 * @param string                            $session Session token.
		 * @param array{lat:float,lng:float}|null $point   Store point (bias).
		 * @return array<int,array{id:string,label:string}>|WP_Error
		 */
		private static function google_suggest( string $query, string $country, string $session, ?array $point ) {
			$body = array(
				'input'               => $query,
				'includedRegionCodes' => array( strtolower( $country ) ),
				'languageCode'        => strtolower( substr( (string) get_locale(), 0, 2 ) ),
			);
			if ( '' !== $session ) {
				$body['sessionToken'] = $session;
			}
			if ( null !== $point ) {
				/**
				 * Filter how far (metres) from the store the search prefers
				 * results (a preference, not a limit). Default 50 km.
				 *
				 * @since 10.4.0
				 * @param float $radius Metres, 1 to 50000.
				 */
				$radius               = min( 50000.0, max( 1.0, (float) apply_filters( 'lafka_address_autocomplete_bias_radius', 50000.0 ) ) );
				$body['locationBias'] = array(
					'circle' => array(
						'center' => array(
							'latitude'  => $point['lat'],
							'longitude' => $point['lng'],
						),
						'radius' => $radius,
					),
				);
			}
			$response = wp_remote_post(
				self::google_endpoint() . '/places:autocomplete',
				array(
					'timeout' => 6,
					'headers' => self::google_headers(),
					'body'    => wp_json_encode( $body ),
				)
			);
			$data     = self::google_json( $response, 'google_autocomplete' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$out = array();
			foreach ( (array) ( $data['suggestions'] ?? array() ) as $suggestion ) {
				$prediction = is_array( $suggestion ) && isset( $suggestion['placePrediction'] ) && is_array( $suggestion['placePrediction'] ) ? $suggestion['placePrediction'] : array();
				$id         = (string) ( $prediction['placeId'] ?? '' );
				$label      = (string) ( $prediction['text']['text'] ?? '' );
				if ( '' === $id || '' === $label ) {
					continue;
				}
				$out[] = array(
					'id'    => $id,
					'label' => $label,
				);
				if ( count( $out ) >= self::MAX_RESULTS ) {
					break;
				}
			}

			return $out;
		}

		/**
		 * Place Details (New) for a chosen suggestion.
		 *
		 * @param string $id      Place id.
		 * @param string $session Session token (closes the billing session).
		 * @return array|WP_Error
		 */
		private static function google_place( string $id, string $session ) {
			$url      = self::google_endpoint() . '/places/' . rawurlencode( $id ) . '?languageCode=' . rawurlencode( strtolower( substr( (string) get_locale(), 0, 2 ) ) ) . ( '' === $session ? '' : '&sessionToken=' . rawurlencode( $session ) );
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 6,
					'headers' => self::google_headers( 'addressComponents,location' ),
				)
			);
			$data     = self::google_json( $response, 'google_place' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$place = self::google_address( $data );

			return null === $place ? new WP_Error( 'lafka_address_unavailable', __( 'That address could not be read. Please type it in full.', 'lafka-plugin' ) ) : $place;
		}

		/**
		 * Read a Places response: the decoded body, or a WP_Error (logged).
		 *
		 * @param array|WP_Error $response HTTP response.
		 * @param string         $code     Log code.
		 * @return array|WP_Error
		 */
		private static function google_json( $response, string $code ) {
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status ) {
				self::log( $code, is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $status );

				return new WP_Error( 'lafka_address_unavailable', __( 'Address suggestions are not available right now.', 'lafka-plugin' ) );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			return is_array( $body ) ? $body : new WP_Error( 'lafka_address_unavailable', __( 'Address suggestions are not available right now.', 'lafka-plugin' ) );
		}

		/**
		 * A Places (New) place as a WooCommerce address.
		 *
		 * @param array $data Place with addressComponents and location.
		 * @return array|null
		 */
		public static function google_address( array $data ): ?array {
			$point = lafka_geo_point( $data['location']['latitude'] ?? null, $data['location']['longitude'] ?? null );
			if ( null === $point ) {
				return null;
			}
			$by_type = array();
			foreach ( (array) ( $data['addressComponents'] ?? array() ) as $component ) {
				if ( ! is_array( $component ) ) {
					continue;
				}
				foreach ( (array) ( $component['types'] ?? array() ) as $type ) {
					if ( ! isset( $by_type[ $type ] ) ) {
						$by_type[ $type ] = $component;
					}
				}
			}
			$long   = static function ( array $types ) use ( $by_type ): string {
				foreach ( $types as $type ) {
					if ( isset( $by_type[ $type ] ) && '' !== trim( (string) ( $by_type[ $type ]['longText'] ?? '' ) ) ) {
						return trim( (string) $by_type[ $type ]['longText'] );
					}
				}
				return '';
			};
			$cc     = strtoupper( (string) ( $by_type['country']['shortText'] ?? '' ) );
			$street = $long( array( 'route' ) );
			$state  = self::state_code( $cc, (string) ( $by_type['administrative_area_level_1']['shortText'] ?? '' ) );
			if ( '' === $state ) {
				$state = self::state_code( $cc, $long( array( 'administrative_area_level_1' ) ) );
			}
			if ( '' === $cc || '' === $street ) {
				return null;
			}

			return array(
				'address_1' => self::street_line( $cc, $long( array( 'street_number' ) ), $street ),
				'address_2' => $long( array( 'subpremise' ) ),
				'city'      => $long( array( 'locality', 'postal_town', 'sublocality_level_1', 'sublocality', 'administrative_area_level_3' ) ),
				'state'     => $state,
				'postcode'  => $long( array( 'postal_code' ) ),
				'country'   => $cc,
				'lat'       => round( $point['lat'], 6 ),
				'lng'       => round( $point['lng'], 6 ),
			);
		}

		/* ------------------------------------------------------------------ *
		 *  Shared helpers
		 * ------------------------------------------------------------------ */

		/**
		 * The street line: number first (CA, US, GB, FR…) or after the street (DE, NL…).
		 *
		 * @param string $country ISO country code.
		 * @param string $number  House number ('' when none).
		 * @param string $street  Street name.
		 * @return string
		 */
		private static function street_line( string $country, string $number, string $street ): string {
			/**
			 * Filter the countries that write the house number after the street.
			 *
			 * @since 10.4.0
			 * @param string[] $countries ISO country codes.
			 */
			$after = (array) apply_filters( 'lafka_address_number_after_street', self::NUMBER_AFTER_STREET );

			return trim( in_array( $country, $after, true ) ? $street . ' ' . $number : $number . ' ' . $street );
		}

		/**
		 * WooCommerce's state code for what a service calls the state: a code
		 * or a name, matched without case or accents. '' when the country has
		 * no states list or nothing matches (the customer then picks it).
		 *
		 * @param string $country ISO country code.
		 * @param string $value   Code or name from the service.
		 * @return string
		 */
		public static function state_code( string $country, string $value ): string {
			$value = trim( $value );
			$wc    = function_exists( 'WC' ) ? WC() : null;
			if ( '' === $value || ! is_object( $wc ) || ! isset( $wc->countries ) || ! is_object( $wc->countries ) ) {
				return '';
			}
			$states = $wc->countries->get_states( $country );
			if ( ! is_array( $states ) || empty( $states ) ) {
				return '';
			}
			if ( isset( $states[ strtoupper( $value ) ] ) ) {
				return strtoupper( $value );
			}
			$fold = static function ( string $text ): string {
				return strtolower( preg_replace( '/[^a-z0-9]/i', '', remove_accents( $text ) ) );
			};
			foreach ( $states as $code => $name ) {
				if ( $fold( (string) $name ) === $fold( $value ) ) {
					return (string) $code;
				}
			}

			return '';
		}

		/**
		 * One-line label of a place for the suggestion list.
		 *
		 * @param array $place Place.
		 * @return string
		 */
		private static function label( array $place ): string {
			$region = trim( $place['state'] . ' ' . $place['postcode'] );

			return implode( ', ', array_filter( array( $place['address_1'], $place['city'], $region ), 'strlen' ) );
		}

		/**
		 * The language the services answer in: the site locale.
		 *
		 * @return string
		 */
		private static function language(): string {
			return str_replace( '_', '-', (string) get_locale() );
		}

		/**
		 * Log a service failure on the checkout channel (no address text).
		 *
		 * @param string $code   Short code.
		 * @param string $detail What went wrong.
		 * @return void
		 */
		private static function log( string $code, string $detail ): void {
			if ( class_exists( 'Lafka_Log' ) ) {
				Lafka_Log::log(
					'warning',
					'checkout',
					'Address autocomplete: ' . $detail,
					array( 'code' => 'address_' . $code )
				);
			}
		}
	}

	Lafka_Address_Search::init();
}
