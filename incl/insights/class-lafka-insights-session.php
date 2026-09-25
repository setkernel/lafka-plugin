<?php
/**
 * Lafka_Insights_Session — cookieless visit stitching + request eligibility.
 *
 * Visit id without cookies:
 *
 *   sid = first 128 bits of sha256( daily_secret | ip | ua_family | site_host )
 *
 * The same function runs in the beacon endpoint and in the Woo hooks, so the
 * client page views and the server money events of one visit join on `sid`
 * without any cookie or stored identifier.
 *
 *   - The secret lives in option `lafka_insights_secret` (autoload off) as
 *     { day, key }. It is replaced the first time it is needed on a new local
 *     day, and the nightly job deletes a secret that belongs to a past day —
 *     so yesterday's sids cannot be recomputed, which makes them unlinkable.
 *   - The IP is used only inside the hash and is never stored. Behind a
 *     reverse proxy the real client IP is resolved by client_ip(): Cloudflare's
 *     CF-Connecting-IP is trusted only when the operator says the site is
 *     behind Cloudflare (Customizer or `lafka_insights_behind_cloudflare`);
 *     anything else goes through the `lafka_insights_client_ip` filter.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Session' ) ) {

	final class Lafka_Insights_Session {

		/** Option holding today's secret. */
		const SECRET_OPTION = 'lafka_insights_secret';

		/** Device classes (sessions.device). */
		const DEVICE_UNKNOWN = 0;
		const DEVICE_MOBILE  = 1;
		const DEVICE_TABLET  = 2;
		const DEVICE_DESKTOP = 3;

		/**
		 * Today's date in the site timezone (Y-m-d).
		 *
		 * @return string
		 */
		public static function today(): string {
			return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}

		/**
		 * The secret for $day (default today), created on first use. Creating a
		 * new day's secret overwrites — and so deletes — the previous one.
		 *
		 * @param string|null $day Y-m-d.
		 * @return string 64 hex chars.
		 */
		public static function secret( ?string $day = null ): string {
			$day    = null === $day ? self::today() : $day;
			$stored = get_option( self::SECRET_OPTION, array() );
			if ( is_array( $stored )
				&& ( $stored['day'] ?? '' ) === $day
				&& is_string( $stored['key'] ?? null )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/', $stored['key'] ) ) {
				return $stored['key'];
			}
			$key = bin2hex( random_bytes( 32 ) );
			update_option(
				self::SECRET_OPTION,
				array(
					'day' => $day,
					'key' => $key,
				),
				false
			);
			return $key;
		}

		/**
		 * Nightly rotation: delete a secret that belongs to a past day (the next
		 * request creates today's). Returns true when a stale secret was deleted.
		 *
		 * @return bool
		 */
		public static function rotate(): bool {
			$stored = get_option( self::SECRET_OPTION, array() );
			if ( is_array( $stored ) && ( $stored['day'] ?? '' ) === self::today() ) {
				return false;
			}
			if ( false === $stored || array() === $stored ) {
				return false;
			}
			delete_option( self::SECRET_OPTION );
			return true;
		}

		/**
		 * The pseudonymous visit id (32 hex chars = BINARY(16)).
		 *
		 * @param string      $ip  Client IP (hashed, never stored).
		 * @param string      $ua  User-Agent (reduced to its family first).
		 * @param string|null $day Y-m-d (default today).
		 * @return string
		 */
		public static function visitor_id( string $ip, string $ua, ?string $day = null ): string {
			$site = class_exists( 'Lafka_Beacon_Guard' ) ? Lafka_Beacon_Guard::site_host() : '';
			return substr( hash( 'sha256', self::secret( $day ) . '|' . $ip . '|' . self::ua_family( $ua ) . '|' . $site ), 0, 32 );
		}

		/**
		 * Visit id for the current request.
		 *
		 * @return string
		 */
		public static function current_visitor_id(): string {
			return self::visitor_id( self::client_ip(), self::user_agent() );
		}

		/**
		 * The current request's User-Agent.
		 *
		 * @return string
		 */
		public static function user_agent(): string {
			if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
				return '';
			}
			return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		}

		/**
		 * Whether the operator declared the site is behind Cloudflare.
		 *
		 * @return bool
		 */
		public static function behind_cloudflare(): bool {
			$on = function_exists( 'get_theme_mod' ) && '1' === (string) get_theme_mod( 'lafka_insights_behind_cloudflare', '0' );
			if ( function_exists( 'apply_filters' ) ) {
				$on = (bool) apply_filters( 'lafka_insights_behind_cloudflare', $on );
			}
			return $on;
		}

		/**
		 * Real client IP: REMOTE_ADDR, or CF-Connecting-IP when (and only when)
		 * the site is declared to be behind Cloudflare. Other proxies: filter
		 * `lafka_insights_client_ip` ( $ip, $remote_addr ).
		 *
		 * @return string '' when unavailable.
		 */
		public static function client_ip(): string {
			$remote = self::server_ip( 'REMOTE_ADDR' );
			$ip     = $remote;
			if ( self::behind_cloudflare() ) {
				$cf = self::server_ip( 'HTTP_CF_CONNECTING_IP' );
				if ( '' !== $cf ) {
					$ip = $cf;
				}
			}
			if ( function_exists( 'apply_filters' ) ) {
				$ip = (string) apply_filters( 'lafka_insights_client_ip', $ip, $remote );
			}
			return $ip;
		}

		/**
		 * A validated IP from a $_SERVER key, '' otherwise.
		 *
		 * @param string $key $_SERVER key.
		 * @return string
		 */
		private static function server_ip( string $key ): string {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				return '';
			}
			$ip = trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
			return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}

		/**
		 * Coarse browser-os family ("chrome-android"), the only UA detail used.
		 *
		 * @param string $ua User-Agent.
		 * @return string
		 */
		public static function ua_family( string $ua ): string {
			$ua = strtolower( $ua );
			if ( '' === $ua ) {
				return 'none';
			}
			$os = 'other';
			foreach ( array(
				'iphone'    => 'ios',
				'ipad'      => 'ios',
				'ipod'      => 'ios',
				'android'   => 'android',
				'windows'   => 'windows',
				'cros'      => 'chromeos',
				'macintosh' => 'mac',
				'mac os x'  => 'mac',
				'linux'     => 'linux',
			) as $needle => $name ) {
				if ( false !== strpos( $ua, $needle ) ) {
					$os = $name;
					break;
				}
			}
			$browser = 'other';
			foreach ( array(
				'edg/'           => 'edge',
				'edga/'          => 'edge',
				'edgios/'        => 'edge',
				'samsungbrowser' => 'samsung',
				'opr/'           => 'opera',
				'opera'          => 'opera',
				'firefox'        => 'firefox',
				'fxios'          => 'firefox',
				'crios'          => 'chrome',
				'chrome'         => 'chrome',
				'safari'         => 'safari',
			) as $needle => $name ) {
				if ( false !== strpos( $ua, $needle ) ) {
					$browser = $name;
					break;
				}
			}
			return $browser . '-' . $os;
		}

		/**
		 * Device class from the UA — used only when the page did not report its
		 * viewport (server-side events that open a visit).
		 *
		 * @param string $ua User-Agent.
		 * @return int
		 */
		public static function device_from_ua( string $ua ): int {
			$ua = strtolower( $ua );
			if ( '' === $ua ) {
				return self::DEVICE_UNKNOWN;
			}
			if ( false !== strpos( $ua, 'ipad' ) || false !== strpos( $ua, 'tablet' ) || ( false !== strpos( $ua, 'android' ) && false === strpos( $ua, 'mobile' ) ) ) {
				return self::DEVICE_TABLET;
			}
			if ( false !== strpos( $ua, 'mobi' ) || false !== strpos( $ua, 'iphone' ) ) {
				return self::DEVICE_MOBILE;
			}
			return self::DEVICE_DESKTOP;
		}

		/**
		 * Staff are never measured: users who can manage the store (and anyone
		 * a site excludes through `lafka_insights_exclude_user`).
		 *
		 * @return bool
		 */
		public static function is_excluded_user(): bool {
			$excluded = function_exists( 'is_user_logged_in' ) && is_user_logged_in()
				&& function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
			if ( function_exists( 'apply_filters' ) ) {
				$excluded = (bool) apply_filters( 'lafka_insights_exclude_user', $excluded );
			}
			return $excluded;
		}

		/**
		 * True when the browser sent a Global Privacy Control or Do Not Track
		 * opt-out signal.
		 *
		 * @return bool
		 */
		public static function has_privacy_signal(): bool {
			foreach ( array( 'HTTP_SEC_GPC', 'HTTP_DNT' ) as $key ) {
				if ( isset( $_SERVER[ $key ] ) && '1' === trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * True when the consent banner recorded an analytics grant (it mirrors
		 * the decision into the first-party `lafka_consent` cookie).
		 *
		 * @return bool
		 */
		public static function has_consent_cookie(): bool {
			$name = Lafka_Insights::CONSENT_COOKIE;
			return isset( $_COOKIE[ $name ] ) && '1' === sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		}

		/**
		 * Whether the current request may be measured at all: not a bot, not
		 * staff, and allowed by the consent mode (aggregate honours GPC/DNT,
		 * consent_required needs the consent cookie, off measures nothing).
		 *
		 * @return bool
		 */
		public static function request_allowed(): bool {
			if ( class_exists( 'Lafka_Beacon_Guard' ) && Lafka_Beacon_Guard::is_bot_ua( self::user_agent() ) ) {
				return false;
			}
			if ( self::is_excluded_user() ) {
				return false;
			}
			switch ( Lafka_Insights::consent_mode() ) {
				case Lafka_Insights::MODE_AGGREGATE:
					return ! self::has_privacy_signal();
				case Lafka_Insights::MODE_CONSENT:
					return self::has_consent_cookie();
				default:
					return false;
			}
		}
	}
}
