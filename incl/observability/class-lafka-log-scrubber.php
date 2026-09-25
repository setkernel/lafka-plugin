<?php
/**
 * Lafka_Log_Scrubber — strip personal data from log messages + context (GX1 / A2).
 *
 * Pure (no I/O, no WordPress state beyond two filters), so every rule is unit
 * tested. Two layers:
 *
 *   1. Key denylist — values under keys such as `email`, `billing_*`,
 *      `address*`, `card*`, `*token*`, `ip`, `user_agent` are replaced with
 *      `[redacted]` whatever they contain.
 *   2. Value patterns — every remaining string is masked for emails, phone
 *      numbers (NANP + E.164), card numbers (13–19 digits, Luhn-checked),
 *      postal codes (CA, UK, US ZIP+4), IPv4/IPv6, bearer tokens and JWTs;
 *      lat/lng pairs with more than 2 decimals are rounded to 2.
 *
 * Order ids and product ids are kept: they are merchant records the admin can
 * already see. Limits: depth 4, strings capped at 500 characters, the whole
 * context capped at 4 KB (JSON) with `_truncated => true` when cut.
 *
 * Extend with the `lafka_log_scrub_keys` (glob patterns, matched on the
 * lower-cased key) and `lafka_log_scrub_patterns` (regex => replacement)
 * filters.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Log_Scrubber' ) ) {

	/**
	 * PII scrubber for log records.
	 */
	final class Lafka_Log_Scrubber {

		const REDACTED          = '[redacted]';
		const MAX_DEPTH         = 4;
		const MAX_STRING        = 500;
		const MAX_CONTEXT_BYTES = 4096;

		/** Keys whose values are coordinates (rounded, not redacted). */
		const COORDINATE_KEYS = array( 'lat', 'lng', 'lon', 'latitude', 'longitude' );

		/**
		 * Default key denylist — glob patterns matched against the lower-cased key.
		 *
		 * @return array<int,string>
		 */
		public static function default_keys(): array {
			return array(
				'email',
				'*email*',
				'*phone*',
				'billing_*',
				'shipping_*',
				'first_name',
				'last_name',
				'full_name',
				'customer_name',
				'customer_note',
				'address*',
				'*_address',
				'street*',
				'postcode',
				'postal_code',
				'zip',
				'zipcode',
				'card*',
				'*card_number*',
				'pan',
				'cvv',
				'cvv2',
				'cvc',
				'password',
				'pass',
				'pwd',
				'*token*',
				'nonce',
				'*nonce',
				'*secret*',
				'api_key',
				'apikey',
				'authorization',
				'cookie*',
				'*cookie',
				'ip',
				'ip_address',
				'remote_addr',
				'client_ip',
				'user_agent',
				'http_user_agent',
			);
		}

		/**
		 * Default value patterns, applied in order: regex => replacement. The
		 * card and IPv6 rules need validation beyond a regex, so they run as
		 * callbacks in scrub_string() and are not listed here.
		 *
		 * @return array<string,string>
		 */
		public static function default_patterns(): array {
			return array(
				// JWT (three base64url segments starting with eyJ).
				'/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/'             => '[token]',
				// Bearer / Basic credentials.
				'/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i'                         => '$1 [token]',
				// Email addresses.
				'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'                           => '[email]',
				// E.164 phone numbers.
				'/(?<![\w+])\+\d{8,15}(?!\d)/'                                         => '[phone]',
				// NANP phone numbers: 902-555-0142, (902) 555-0142, 1 902 555 0142.
				'/(?<![\w])(?:\+?1[\s.\-]?)?(?:\(\d{3}\)|\d{3})[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\d)/' => '[phone]',
				// Canadian postal codes (A1A 1A1).
				'/\b[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z][ \-]?\d[ABCEGHJ-NPRSTV-Z]\d\b/i' => '[postcode]',
				// UK postcodes (upper-case, as written on addresses).
				'/\b[A-Z]{1,2}\d[A-Z\d]?\s+\d[A-Z]{2}\b/'                             => '[postcode]',
				// US ZIP+4.
				'/\b\d{5}-\d{4}\b/'                                                    => '[postcode]',
				// IPv4.
				'/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\d.])/' => '[ip]',
			);
		}

		/**
		 * Scrub a context array (keys + values, bounded).
		 *
		 * @param mixed $context Context (non-arrays are wrapped as `value`).
		 * @return array<string|int,mixed>
		 */
		public static function scrub( $context ): array {
			if ( ! is_array( $context ) ) {
				$context = array( 'value' => $context );
			}
			$keys     = self::keys();
			$patterns = self::patterns();
			$out      = self::scrub_array( $context, 1, $keys, $patterns );

			return self::bound( $out );
		}

		/**
		 * Mask personal data inside one string and cap its length.
		 *
		 * @param string $value Input.
		 * @return string
		 */
		public static function scrub_string( string $value ): string {
			return self::mask( $value, self::patterns() );
		}

		// ─── Internals ──────────────────────────────────────────────────────

		/**
		 * @param array<string|int,mixed> $data     Array to scrub.
		 * @param int                     $depth    Current depth (1 = top level).
		 * @param array<int,string>       $keys     Key denylist (glob).
		 * @param array<string,string>    $patterns Value patterns.
		 * @return array<string|int,mixed>
		 */
		private static function scrub_array( array $data, int $depth, array $keys, array $patterns ): array {
			$out = array();
			foreach ( $data as $key => $value ) {
				$lower = strtolower( (string) $key );

				if ( is_string( $key ) && self::key_is_denied( $lower, $keys ) ) {
					$out[ $key ] = self::REDACTED;
					continue;
				}
				if ( in_array( $lower, self::COORDINATE_KEYS, true ) && is_numeric( $value ) ) {
					$out[ $key ] = round( (float) $value, 2 );
					continue;
				}
				$out[ $key ] = self::scrub_value( $value, $depth, $keys, $patterns );
			}
			return $out;
		}

		/**
		 * @param mixed                $value    Value.
		 * @param int                  $depth    Depth of the containing array.
		 * @param array<int,string>    $keys     Key denylist.
		 * @param array<string,string> $patterns Value patterns.
		 * @return mixed
		 */
		private static function scrub_value( $value, int $depth, array $keys, array $patterns ) {
			if ( is_array( $value ) ) {
				return $depth >= self::MAX_DEPTH ? '[depth]' : self::scrub_array( $value, $depth + 1, $keys, $patterns );
			}
			if ( $value instanceof \Throwable ) {
				return array(
					'class'   => get_class( $value ),
					'message' => self::mask( $value->getMessage(), $patterns ),
					'file'    => self::relative_path( $value->getFile() ),
					'line'    => $value->getLine(),
				);
			}
			if ( is_object( $value ) ) {
				if ( class_exists( 'WP_Error' ) && $value instanceof \WP_Error ) {
					return array( 'wp_error_codes' => array_map( 'strval', (array) $value->get_error_codes() ) );
				}
				return '[object ' . get_class( $value ) . ']';
			}
			if ( is_string( $value ) ) {
				return self::mask( $value, $patterns );
			}
			if ( is_resource( $value ) ) {
				return '[resource]';
			}
			return $value; // int / float / bool / null.
		}

		/**
		 * @param string               $value    String.
		 * @param array<string,string> $patterns Value patterns.
		 * @return string
		 */
		private static function mask( string $value, array $patterns ): string {
			if ( '' === $value ) {
				return $value;
			}

			// Card numbers first: only digit runs that pass Luhn are cards, so a
			// gateway transaction id is never mistaken for one.
			$value = (string) preg_replace_callback(
				'/(?<![\d.])(?:\d[ \-]?){12,18}\d(?![\d.])/',
				static function ( $m ) {
					$digits = preg_replace( '/\D/', '', $m[0] );
					return self::luhn( (string) $digits ) ? '[card]' : $m[0];
				},
				$value
			);

			// Lat/lng pairs with more than 2 decimals → 2 decimals (~1 km).
			$value = (string) preg_replace_callback(
				'/(?<![\d.])(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})(?![\d.])/',
				static function ( $m ) {
					return number_format( round( (float) $m[1], 2 ), 2, '.', '' ) . ', ' . number_format( round( (float) $m[2], 2 ), 2, '.', '' );
				},
				$value
			);

			foreach ( $patterns as $regex => $replacement ) {
				$result = @preg_replace( (string) $regex, (string) $replacement, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filtered-in bad regex must never break logging.
				if ( is_string( $result ) ) {
					$value = $result;
				}
			}

			// IPv6: validate candidates so clock times (20:22:14) and PHP
			// Class::method references are never masked.
			$value = (string) preg_replace_callback(
				'/(?<![\w:])(?:[A-Fa-f0-9]{0,4}:){2,7}[A-Fa-f0-9]{0,4}(?![\w:])/',
				static function ( $m ) {
					$candidate = $m[0];
					if ( substr_count( $candidate, ':' ) >= 3
						&& preg_match( '/\d/', $candidate )
						&& false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
						return '[ip]';
					}
					return $candidate;
				},
				$value
			);

			if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > self::MAX_STRING ) {
				$value = mb_substr( $value, 0, self::MAX_STRING ) . '…';
			} elseif ( ! function_exists( 'mb_strlen' ) && strlen( $value ) > self::MAX_STRING ) {
				$value = substr( $value, 0, self::MAX_STRING ) . '…';
			}
			return $value;
		}

		/**
		 * Luhn checksum for a 13–19 digit candidate.
		 *
		 * @param string $digits Digits only.
		 * @return bool
		 */
		private static function luhn( string $digits ): bool {
			$len = strlen( $digits );
			if ( $len < 13 || $len > 19 ) {
				return false;
			}
			$sum    = 0;
			$double = false;
			for ( $i = $len - 1; $i >= 0; $i-- ) {
				$d = (int) $digits[ $i ];
				if ( $double ) {
					$d *= 2;
					if ( $d > 9 ) {
						$d -= 9;
					}
				}
				$sum   += $d;
				$double = ! $double;
			}
			return 0 === $sum % 10;
		}

		/**
		 * @param string            $lower Lower-cased key.
		 * @param array<int,string> $keys  Glob patterns.
		 * @return bool
		 */
		private static function key_is_denied( string $lower, array $keys ): bool {
			foreach ( $keys as $pattern ) {
				$pattern = strtolower( (string) $pattern );
				if ( $pattern === $lower ) {
					return true;
				}
				if ( false !== strpos( $pattern, '*' ) ) {
					$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
					if ( preg_match( $regex, $lower ) ) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Keep an (already scrubbed) array under $max_bytes of JSON: whole
		 * top-level entries are kept in order while they fit; `_truncated`
		 * marks a cut.
		 *
		 * @param array<string|int,mixed> $data      Scrubbed context.
		 * @param int                     $max_bytes JSON byte budget.
		 * @return array<string|int,mixed>
		 */
		public static function bound( array $data, int $max_bytes = self::MAX_CONTEXT_BYTES ): array {
			$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			if ( is_string( $json ) && strlen( $json ) <= $max_bytes ) {
				return $data;
			}
			$out    = array();
			$budget = $max_bytes - 32;
			foreach ( $data as $key => $value ) {
				$candidate         = $out;
				$candidate[ $key ] = $value;
				$size              = strlen( (string) json_encode( $candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
				if ( $size > $budget ) {
					break;
				}
				$out = $candidate;
			}
			$out['_truncated'] = true;
			return $out;
		}

		/**
		 * Path relative to wp-content (or the basename) — no server layout leak.
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		public static function relative_path( string $path ): string {
			$path = str_replace( '\\', '/', $path );
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$root = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/';
				if ( 0 === strpos( $path, $root ) ) {
					return substr( $path, strlen( $root ) );
				}
			}
			foreach ( array( '/plugins/', '/themes/' ) as $marker ) {
				$pos = strpos( $path, $marker );
				if ( false !== $pos ) {
					return ltrim( substr( $path, $pos ), '/' );
				}
			}
			return basename( $path );
		}

		/** @return array<int,string> */
		private static function keys(): array {
			$keys = self::default_keys();
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_log_scrub_keys', $keys );
				if ( is_array( $filtered ) ) {
					$keys = $filtered;
				}
			}
			return array_values( array_filter( array_map( 'strval', $keys ) ) );
		}

		/** @return array<string,string> */
		private static function patterns(): array {
			$patterns = self::default_patterns();
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_log_scrub_patterns', $patterns );
				if ( is_array( $filtered ) ) {
					$patterns = $filtered;
				}
			}
			return $patterns;
		}
	}
}
