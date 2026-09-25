<?php
/**
 * Lafka_Insights_Collector — POST /wp-json/lafka/v1/i, the one-per-page beacon.
 *
 * Body (text/plain JSON from navigator.sendBeacon, ≤ 2 KB, strict whitelist):
 *
 *   {
 *     "v": 1,                      schema version
 *     "p": "/menu/",               path (no query string)
 *     "t": "product",              page_type from the page_context push
 *     "r": "www.google.com",       referrer HOST only
 *     "us": "", "um": "", "uc": "", utm_source / utm_medium / utm_campaign
 *     "d": "m" | "t" | "d",        viewport device class
 *     "e": [ ["l"], ["v","123"], ["i","123"], ["s","pizza",4], ["c"],
 *            ["f","pickup"], ["o","direct"], ["h"] ]   ≤ 30 events
 *   }
 *
 * Event codes: l view_item_list · v view_item · i select_item · s search
 * (term, results) · c store_closed_view · f select_fulfilment · o
 * order_channel_click · h add_shipping_info. Anything else — unknown keys,
 * unknown codes, wrong types — rejects the whole payload (400).
 *
 * Guards (Lafka_Beacon_Guard): same-origin, body cap, bot UA, staff +
 * consent-mode eligibility, a per-visit daily beacon cap and a site-wide
 * hourly cap. The caps are read with ONE query and enforced from the
 * counters the two writes maintain anyway, so the endpoint costs at most one
 * read and two writes (session upsert + counter upsert) — no transient.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Collector' ) ) {

	final class Lafka_Insights_Collector {

		const MAX_BODY   = 2048;
		const MAX_EVENTS = 30;

		/** Allowed top-level keys. */
		const KEYS = array( 'v', 'p', 't', 'r', 'us', 'um', 'uc', 'd', 'e' );

		/**
		 * Register the route (rest_api_init).
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			register_rest_route(
				Lafka_Insights::REST_NS,
				'/i',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				)
			);
		}

		/**
		 * Same-origin gate (anonymous beacons carry no nonce — see the guard).
		 *
		 * @param object $request WP_REST_Request.
		 * @return true|WP_Error
		 */
		public static function permission( $request ) {
			if ( Lafka_Beacon_Guard::is_same_origin( $request ) ) {
				return true;
			}
			return new WP_Error( 'lafka_insights_origin', 'Cross-origin beacon refused.', array( 'status' => 403 ) );
		}

		/**
		 * Handle one beacon.
		 *
		 * @param object $request WP_REST_Request.
		 * @return WP_REST_Response|array
		 */
		public static function handle( $request ) {
			$raw = Lafka_Beacon_Guard::body( $request, self::MAX_BODY );
			if ( null === $raw ) {
				return Lafka_Beacon_Guard::reply( 413 );
			}
			if ( ! Lafka_Insights_Session::request_allowed() ) {
				return Lafka_Beacon_Guard::reply( 204 );
			}
			$payload = self::parse( $raw );
			if ( null === $payload ) {
				return Lafka_Beacon_Guard::reply( 400 );
			}

			$day  = Lafka_Insights_Session::today();
			$hour = function_exists( 'wp_date' ) ? (string) wp_date( 'H' ) : gmdate( 'H' );
			$sid  = Lafka_Insights_Session::current_visitor_id();

			list( $visit_beacons, $hour_beacons ) = Lafka_Insights_DB::beacon_counts( $day, $sid, $hour );
			if ( $visit_beacons >= self::visit_cap() || $hour_beacons >= self::global_cap() ) {
				return Lafka_Beacon_Guard::reply( 429 );
			}

			$derived = self::derive( $payload, Lafka_Beacon_Guard::site_host(), self::menu_path(), self::store_is_open() );
			$row     = $derived['row'];
			if ( Lafka_Insights_Session::DEVICE_UNKNOWN === $row['device'] ) {
				$row['device'] = Lafka_Insights_Session::device_from_ua( Lafka_Insights_Session::user_agent() );
			}
			$row['hour']      = (int) $hour;
			$row['dow']       = function_exists( 'wp_date' ) ? (int) wp_date( 'w' ) : (int) gmdate( 'w' );
			$row['pageviews'] = 1;

			Lafka_Insights_DB::upsert_session( $day, $sid, $row );
			$counters            = $derived['counters'];
			$counters['beacons'] = array( $hour => 1 );
			Lafka_Insights_DB::add_counters( $day, $counters );

			return Lafka_Beacon_Guard::reply( 204 );
		}

		/**
		 * Per-visit beacons per day before 429 (filter `lafka_insights_visit_cap`).
		 *
		 * @return int
		 */
		public static function visit_cap(): int {
			$cap = 300;
			if ( function_exists( 'apply_filters' ) ) {
				$cap = (int) apply_filters( 'lafka_insights_visit_cap', $cap );
			}
			return $cap > 0 ? $cap : PHP_INT_MAX;
		}

		/**
		 * Site-wide beacons per hour before 429 (filter `lafka_insights_global_cap`).
		 *
		 * @return int
		 */
		public static function global_cap(): int {
			$cap = 5000;
			if ( function_exists( 'apply_filters' ) ) {
				$cap = (int) apply_filters( 'lafka_insights_global_cap', $cap );
			}
			return $cap > 0 ? $cap : PHP_INT_MAX;
		}

		/**
		 * Parse + validate a beacon body against the strict schema.
		 *
		 * @param string $raw Raw body.
		 * @return array<string,mixed>|null Normalised payload, or null when invalid.
		 */
		public static function parse( string $raw ): ?array {
			if ( '' === $raw || strlen( $raw ) > self::MAX_BODY ) {
				return null;
			}
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) ) {
				return null;
			}
			if ( array_diff( array_keys( $data ), self::KEYS ) ) {
				return null;
			}
			foreach ( array( 'p', 't', 'r', 'us', 'um', 'uc', 'd' ) as $key ) {
				if ( isset( $data[ $key ] ) && ! is_string( $data[ $key ] ) ) {
					return null;
				}
			}

			$out = array(
				'p'  => self::clean_path( (string) ( $data['p'] ?? '' ) ),
				't'  => self::clean_slug( (string) ( $data['t'] ?? '' ), 16 ),
				'r'  => self::clean_host( (string) ( $data['r'] ?? '' ) ),
				'us' => self::clean_utm( (string) ( $data['us'] ?? '' ) ),
				'um' => self::clean_utm( (string) ( $data['um'] ?? '' ), 32 ),
				'uc' => self::clean_utm( (string) ( $data['uc'] ?? '' ) ),
				'd'  => in_array( $data['d'] ?? '', array( 'm', 't', 'd' ), true ) ? $data['d'] : '',
				'e'  => array(),
			);

			$events = $data['e'] ?? array();
			if ( ! is_array( $events ) || count( $events ) > self::MAX_EVENTS ) {
				return null;
			}
			foreach ( $events as $event ) {
				$clean = self::clean_event( $event );
				if ( null === $clean ) {
					return null;
				}
				$out['e'][] = $clean;
			}
			return $out;
		}

		/**
		 * Validate one event tuple.
		 *
		 * @param mixed $event Raw event.
		 * @return array<int,mixed>|null
		 */
		private static function clean_event( $event ): ?array {
			if ( ! is_array( $event ) || ! isset( $event[0] ) || ! is_string( $event[0] ) || count( $event ) > 3 ) {
				return null;
			}
			switch ( $event[0] ) {
				case 'l':
				case 'c':
				case 'h':
					return array( $event[0] );
				case 'v':
				case 'i':
					$id = $event[1] ?? '';
					if ( ( ! is_string( $id ) && ! is_int( $id ) ) || 1 !== preg_match( '/^\d{1,20}$/', (string) $id ) ) {
						return null;
					}
					return array( $event[0], (string) (int) $id );
				case 's':
					$term  = $event[1] ?? '';
					$count = $event[2] ?? 0;
					if ( ! is_string( $term ) || ! is_int( $count ) || $count < 0 ) {
						return null;
					}
					return array( 's', mb_substr( $term, 0, 64 ), min( $count, 9999 ) );
				case 'f':
				case 'o':
					$slug = $event[1] ?? '';
					if ( ! is_string( $slug ) ) {
						return null;
					}
					return array( $event[0], self::clean_slug( $slug, 24 ) );
			}
			return null;
		}

		/**
		 * Turn a validated payload into the session-row fields and the counter
		 * increments. Pure (no WordPress calls) — unit-tested directly.
		 *
		 * @param array<string,mixed> $payload   Output of parse().
		 * @param string              $site_host The site's host.
		 * @param string              $menu_path Path of the menu page ('' = unknown).
		 * @param bool                $open      Whether the store is open right now.
		 * @return array{row:array<string,mixed>,counters:array<string,array<string,int>>}
		 */
		public static function derive( array $payload, string $site_host, string $menu_path, bool $open ): array {
			$stages   = Lafka_Insights_DB::STAGE_VISIT;
			$counters = array();
			$type     = (string) ( $payload['t'] ?? '' );
			$path     = (string) ( $payload['p'] ?? '' );

			if ( in_array( $type, array( 'shop', 'category' ), true ) || ( '' !== $menu_path && self::slash( $path ) === $menu_path ) ) {
				$stages |= Lafka_Insights_DB::STAGE_MENU;
			}
			if ( 'product' === $type ) {
				$stages |= Lafka_Insights_DB::STAGE_PRODUCT;
			}
			if ( ! $open ) {
				$stages |= Lafka_Insights_DB::STAGE_CLOSED;
			}

			foreach ( (array) ( $payload['e'] ?? array() ) as $event ) {
				switch ( $event[0] ) {
					case 'l':
					case 'i':
						$stages |= Lafka_Insights_DB::STAGE_MENU;
						break;
					case 'v':
						$stages                                  |= Lafka_Insights_DB::STAGE_PRODUCT;
						$counters['item_view'][ $event[1] ]       = ( $counters['item_view'][ $event[1] ] ?? 0 ) + 1;
						break;
					case 's':
						$term = self::normalize_search_term( (string) $event[1] );
						if ( '' !== $term ) {
							$metric                        = 0 === (int) $event[2] ? 'search_zero' : 'search';
							$counters[ $metric ][ $term ]  = ( $counters[ $metric ][ $term ] ?? 0 ) + 1;
						}
						$stages |= Lafka_Insights_DB::STAGE_MENU;
						break;
					case 'c':
						$stages |= Lafka_Insights_DB::STAGE_CLOSED;
						break;
					case 'f':
						if ( '' !== $event[1] ) {
							$counters['fulfilment'][ $event[1] ] = ( $counters['fulfilment'][ $event[1] ] ?? 0 ) + 1;
						}
						break;
					case 'o':
						if ( '' !== $event[1] ) {
							$counters['order_channel'][ $event[1] ] = ( $counters['order_channel'][ $event[1] ] ?? 0 ) + 1;
						}
						break;
					case 'h':
						$stages |= Lafka_Insights_DB::STAGE_CHECKOUT;
						break;
				}
			}

			$source = self::classify_source(
				(string) ( $payload['r'] ?? '' ),
				(string) ( $payload['us'] ?? '' ),
				(string) ( $payload['um'] ?? '' ),
				(string) ( $payload['uc'] ?? '' ),
				$site_host
			);

			$device = array(
				'm' => Lafka_Insights_Session::DEVICE_MOBILE,
				't' => Lafka_Insights_Session::DEVICE_TABLET,
				'd' => Lafka_Insights_Session::DEVICE_DESKTOP,
			);

			return array(
				'row'      => array(
					'stages'      => $stages,
					'device'      => $device[ $payload['d'] ?? '' ] ?? Lafka_Insights_Session::DEVICE_UNKNOWN,
					'source_type' => $source['type'],
					'source'      => $source['source'],
					'medium'      => $source['medium'],
					'campaign'    => $source['campaign'],
					'landing'     => '' !== $source['type'] ? self::landing_bucket( $type, $path ) : '',
				),
				'counters' => $counters,
			);
		}

		/**
		 * Classify where a page view came from, in WooCommerce Order Attribution's
		 * vocabulary (utm / organic / referral / typein) so visits and orders
		 * join by source type. An internal referrer (the site itself) returns an
		 * empty type: it is a later page of an existing visit, not an entry.
		 *
		 * @param string $ref_host  Referrer host.
		 * @param string $us        utm_source.
		 * @param string $um        utm_medium.
		 * @param string $uc        utm_campaign.
		 * @param string $site_host The site's host.
		 * @return array{type:string,source:string,medium:string,campaign:string}
		 */
		public static function classify_source( string $ref_host, string $us, string $um, string $uc, string $site_host ): array {
			$ref_host  = strtolower( $ref_host );
			$site_bare = preg_replace( '/^www\./', '', strtolower( $site_host ) );
			$ref_bare  = preg_replace( '/^www\./', '', $ref_host );

			if ( '' !== $us ) {
				return array(
					'type'     => 'utm',
					'source'   => $us,
					'medium'   => '' !== $um ? $um : '(none)',
					'campaign' => $uc,
				);
			}
			if ( '' !== $ref_bare && '' !== $site_bare && $ref_bare === $site_bare ) {
				return array(
					'type'     => '',
					'source'   => '',
					'medium'   => '',
					'campaign' => '',
				);
			}
			if ( '' === $ref_bare ) {
				return array(
					'type'     => 'typein',
					'source'   => '(direct)',
					'medium'   => '(none)',
					'campaign' => '',
				);
			}
			$organic = '/(^|\.)(google|bing|yahoo|duckduckgo|ecosia|baidu|yandex|startpage|qwant|naver|search\.brave|brave)\.[a-z.]+$/';
			if ( function_exists( 'apply_filters' ) ) {
				$organic = (string) apply_filters( 'lafka_insights_search_engine_pattern', $organic );
			}
			$is_organic = '' !== $organic && 1 === preg_match( $organic, $ref_bare );
			return array(
				'type'     => $is_organic ? 'organic' : 'referral',
				'source'   => substr( $ref_bare, 0, 64 ),
				'medium'   => $is_organic ? 'organic' : 'referral',
				'campaign' => '',
			);
		}

		/**
		 * Landing page bucket (a page type, never a raw URL).
		 *
		 * @param string $type Page type from page_context.
		 * @param string $path Path.
		 * @return string
		 */
		public static function landing_bucket( string $type, string $path ): string {
			if ( '' !== $type ) {
				return $type;
			}
			return '/' === $path ? 'home' : 'other';
		}

		/**
		 * Search-term hygiene: lowercase, trim, collapse whitespace, ≤64 chars;
		 * terms that look like an email, a phone number or a long digit run are
		 * dropped ('' returned) so no personal data lands in the counters.
		 *
		 * @param string $term Raw term.
		 * @return string
		 */
		public static function normalize_search_term( string $term ): string {
			$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );
			$term = trim( (string) preg_replace( '/\s+/u', ' ', $term ) );
			$term = function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 64, 'UTF-8' ) : substr( $term, 0, 64 );
			if ( '' === $term ) {
				return '';
			}
			if ( preg_match( '/@|\d[\d\s().+-]{5,}\d|\d{5,}/', $term ) ) {
				return '';
			}
			return $term;
		}

		/**
		 * Path of the menu page on this site ('' when not on this host).
		 *
		 * @return string
		 */
		public static function menu_path(): string {
			if ( ! function_exists( 'lafka_get_menu_url' ) ) {
				return '';
			}
			$url  = lafka_get_menu_url();
			$host = Lafka_Beacon_Guard::host_of( $url );
			if ( '' === $url || $host !== Lafka_Beacon_Guard::site_host() ) {
				return '';
			}
			$path = (string) parse_url( $url, PHP_URL_PATH );
			return self::slash( '' === $path ? '/' : $path );
		}

		/**
		 * Whether the store accepts orders right now (order-hours module), for
		 * the "visited while closed" flag. Filter `lafka_insights_store_is_open`.
		 *
		 * @return bool
		 */
		public static function store_is_open(): bool {
			$open = true;
			if ( Lafka_Options::is_enabled( 'order_hours' ) && class_exists( 'Lafka_Order_Hours' ) && method_exists( 'Lafka_Order_Hours', 'is_shop_open' ) ) {
				$open = (bool) Lafka_Order_Hours::is_shop_open();
			}
			if ( function_exists( 'apply_filters' ) ) {
				$open = (bool) apply_filters( 'lafka_insights_store_is_open', $open );
			}
			return $open;
		}

		/**
		 * trailingslashit() without depending on WordPress being loaded.
		 *
		 * @param string $path Path.
		 * @return string
		 */
		private static function slash( string $path ): string {
			return rtrim( $path, '/\\' ) . '/';
		}

		/**
		 * @param string $path Raw path.
		 * @return string
		 */
		private static function clean_path( string $path ): string {
			$path = (string) strtok( $path, '?#' );
			if ( '' === $path || '/' !== $path[0] ) {
				return '';
			}
			return substr( (string) preg_replace( '/[^A-Za-z0-9\/._~%-]/', '', $path ), 0, 100 );
		}

		/**
		 * @param string $value Raw.
		 * @param int    $max   Max length.
		 * @return string
		 */
		private static function clean_slug( string $value, int $max ): string {
			return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, $max );
		}

		/**
		 * @param string $host Raw host.
		 * @return string
		 */
		private static function clean_host( string $host ): string {
			$host = strtolower( trim( $host ) );
			return 1 === preg_match( '/^[a-z0-9.-]{1,253}$/', $host ) ? substr( $host, 0, 64 ) : '';
		}

		/**
		 * @param string $value Raw UTM value.
		 * @param int    $max   Max length.
		 * @return string
		 */
		private static function clean_utm( string $value, int $max = 64 ): string {
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
			$value = (string) preg_replace( '/[^\p{L}\p{N} ._+\/-]/u', '', $value );
			return trim( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max ) );
		}
	}
}
