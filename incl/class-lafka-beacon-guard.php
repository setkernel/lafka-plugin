<?php
/**
 * Lafka_Beacon_Guard — shared defences for the anonymous, write-only telemetry
 * endpoints (`POST /lafka/v1/i` Insights beacon, `POST /lafka/v1/diag` JS error
 * beacon).
 *
 * Why no nonce for anonymous visitors: both beacons are posted from pages that
 * a full-page cache (Cloudflare APO, WP Rocket, …) serves to everyone, so a
 * nonce baked into the HTML goes stale and every beacon would 403. CSRF
 * protection is also meaningless for anonymous write-only telemetry. The
 * endpoints instead layer these guards:
 *
 *   - same-origin: the Origin (or, failing that, Referer) host must be the
 *     site's own host — a cross-site page cannot spray the endpoint.
 *   - body cap: the raw body must be non-empty and at most N bytes.
 *   - bot filter: crawler / monitor / headless user agents are dropped.
 *   - rate limit: rate_limited() — the push-subscribe limiter pattern
 *     (hashed key, filterable limit + window, 0 disables) generalised to any
 *     bucket, with a global cap per bucket. Uses the persistent object cache
 *     when one is installed (no DB write), otherwise ONE transient per bucket.
 *
 * Logged-in visitors are handled by WordPress core: a cookie-authenticated
 * REST request carrying a valid `_wpnonce` runs as that user, without one it
 * is demoted to anonymous — so the endpoints can exclude staff by capability.
 *
 * @package Lafka\Plugin
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Beacon_Guard' ) ) {

	final class Lafka_Beacon_Guard {

		/** Keys tracked per rate-limit bucket before the oldest are evicted. */
		const MAX_TRACKED_KEYS = 500;

		/**
		 * The site's own host (lowercased, no port), from home_url().
		 *
		 * @return string
		 */
		public static function site_host(): string {
			$home = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
			$host = (string) parse_url( $home, PHP_URL_HOST );
			return strtolower( $host );
		}

		/**
		 * Host of a URL-ish header value ('' when unparseable).
		 *
		 * @param string $value Origin or Referer header.
		 * @return string
		 */
		public static function host_of( string $value ): string {
			$value = trim( $value );
			if ( '' === $value || 'null' === strtolower( $value ) ) {
				return '';
			}
			$host = parse_url( $value, PHP_URL_HOST );
			return is_string( $host ) ? strtolower( $host ) : '';
		}

		/**
		 * Same-origin check: the Origin header (browsers send it on every
		 * sendBeacon POST) — or the Referer when Origin is absent — must name the
		 * site's own host. A request with neither is refused.
		 *
		 * @param object $request WP_REST_Request (anything with get_header()).
		 * @return bool
		 */
		public static function is_same_origin( $request ): bool {
			if ( ! is_object( $request ) || ! method_exists( $request, 'get_header' ) ) {
				return false;
			}
			$site = self::site_host();
			if ( '' === $site ) {
				return false;
			}
			$origin = (string) $request->get_header( 'origin' );
			$host   = self::host_of( $origin );
			if ( '' === $host ) {
				$host = self::host_of( (string) $request->get_header( 'referer' ) );
			}
			return '' !== $host && $host === $site;
		}

		/**
		 * The raw request body when it is non-empty and within the byte cap,
		 * else null.
		 *
		 * @param object $request   WP_REST_Request.
		 * @param int    $max_bytes Cap.
		 * @return string|null
		 */
		public static function body( $request, int $max_bytes ): ?string {
			if ( ! is_object( $request ) || ! method_exists( $request, 'get_body' ) ) {
				return null;
			}
			$raw = (string) $request->get_body();
			if ( '' === $raw || strlen( $raw ) > $max_bytes ) {
				return null;
			}
			return $raw;
		}

		/**
		 * True for crawler / monitor / scripted user agents (and an empty UA).
		 * The pattern is filterable via `lafka_beacon_bot_pattern`.
		 *
		 * @param string $ua User-Agent header.
		 * @return bool
		 */
		public static function is_bot_ua( string $ua ): bool {
			$ua = trim( $ua );
			if ( '' === $ua ) {
				return true;
			}
			$pattern = '/bot\b|bot\/|crawl|spider|slurp|headless|lighthouse|pagespeed|pingdom|uptime|monitor|curl\/|wget|python|go-http|java\/|okhttp|axios|node-fetch|http-client|facebookexternalhit|embedly|preview|scanner|wordpress\//i';
			if ( function_exists( 'apply_filters' ) ) {
				$pattern = (string) apply_filters( 'lafka_beacon_bot_pattern', $pattern );
			}
			return '' !== $pattern && 1 === preg_match( $pattern, $ua );
		}

		/**
		 * Fixed-window rate limiter with a per-key and a global cap.
		 *
		 * The push-subscribe limiter pattern (hashed key into a transient,
		 * filterable limit/window, a limit of 0 disables) generalised: one
		 * record per bucket holds the window start, the global count and a
		 * bounded map of hashed-key counts, so a check costs ONE cache/option
		 * write. On a persistent object cache nothing touches the database.
		 *
		 * Filters: `lafka_rate_limit_{bucket}` (per-key limit),
		 * `lafka_rate_limit_{bucket}_global` (global limit),
		 * `lafka_rate_limit_{bucket}_window` (seconds).
		 *
		 * @param string $bucket       Bucket slug ([a-z0-9_]).
		 * @param string $key          Already-hashed caller key (never an IP).
		 * @param int    $limit        Max hits per key per window (0 = no per-key cap).
		 * @param int    $global_limit Max hits in the bucket per window (0 = no cap).
		 * @param int    $window       Window length in seconds.
		 * @return bool True when this hit must be rejected.
		 */
		public static function rate_limited( string $bucket, string $key, int $limit, int $global_limit, int $window ): bool {
			$bucket = preg_replace( '/[^a-z0-9_]/', '', strtolower( $bucket ) );
			if ( function_exists( 'apply_filters' ) ) {
				$limit        = (int) apply_filters( "lafka_rate_limit_{$bucket}", $limit );
				$global_limit = (int) apply_filters( "lafka_rate_limit_{$bucket}_global", $global_limit );
				$window       = (int) apply_filters( "lafka_rate_limit_{$bucket}_window", $window );
			}
			if ( $limit <= 0 && $global_limit <= 0 ) {
				return false;
			}
			if ( $window <= 0 ) {
				$window = 60;
			}

			$now       = time();
			$cache_key = 'lafka_rl_' . $bucket;
			$use_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
			$state     = $use_cache ? wp_cache_get( $cache_key, 'lafka' ) : get_transient( $cache_key );

			if ( ! is_array( $state ) || ! isset( $state['t'], $state['g'], $state['k'] ) || ( $now - (int) $state['t'] ) >= $window ) {
				$state = array(
					't' => $now,
					'g' => 0,
					'k' => array(),
				);
			}

			$hkey  = substr( md5( $key ), 0, 12 );
			$count = isset( $state['k'][ $hkey ] ) ? (int) $state['k'][ $hkey ] : 0;
			if ( ( $limit > 0 && $count >= $limit ) || ( $global_limit > 0 && (int) $state['g'] >= $global_limit ) ) {
				return true;
			}

			$state['g']++;
			$state['k'][ $hkey ] = $count + 1;
			if ( count( $state['k'] ) > self::MAX_TRACKED_KEYS ) {
				$state['k'] = array_slice( $state['k'], -self::MAX_TRACKED_KEYS, null, true );
			}

			$ttl = max( 1, $window - ( $now - (int) $state['t'] ) );
			if ( $use_cache ) {
				wp_cache_set( $cache_key, $state, 'lafka', $ttl );
			} else {
				set_transient( $cache_key, $state, $ttl );
			}
			return false;
		}

		/**
		 * A 204 No Content REST response (the beacon reply: nothing to say).
		 *
		 * @param int $status HTTP status.
		 * @return WP_REST_Response|array
		 */
		public static function reply( int $status = 204 ) {
			if ( class_exists( 'WP_REST_Response' ) ) {
				return new WP_REST_Response( null, $status );
			}
			return array( 'status' => $status );
		}
	}
}
