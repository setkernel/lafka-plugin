<?php
/**
 * Lafka_Diag_Beacon — front-end JavaScript error beacon (A6).
 *
 *   - wp_head (priority 1): prints assets/js/lafka-diag.min.js inline (≤ 700 B)
 *     with its config. It reports uncaught `error` / `unhandledrejection`
 *     events thrown by same-origin scripts only, ≤ 3 per page and ≤ 10 per tab
 *     session, via navigator.sendBeacon.
 *   - POST /wp-json/lafka/v1/diag: same-origin, ≤ 2 KB strict schema, bot
 *     filter, rate limit per hashed visitor (30/hour) + global (500/hour)
 *     through Lafka_Beacon_Guard::rate_limited(). Writes the error through
 *     lafka_log( 'warning', 'js', … ) when the Lafka logging facade is loaded,
 *     else straight to WooCommerce's logger (source `lafka-js`), so it shows up
 *     in WooCommerce → Status → Logs either way.
 *
 * On when the `diagnostics` or `insights` module is enabled; filter
 * `lafka_diag_js_beacon_enabled` overrides. Never printed on admin screens or
 * the kitchen display.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Diag_Beacon' ) ) {

	final class Lafka_Diag_Beacon {

		const MAX_BODY = 2048;

		/**
		 * Whether the beacon is on.
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$on = class_exists( 'Lafka_Options' ) && ( Lafka_Options::is_enabled( 'diagnostics' ) || Lafka_Options::is_enabled( 'insights' ) );
			if ( function_exists( 'apply_filters' ) ) {
				$on = (bool) apply_filters( 'lafka_diag_js_beacon_enabled', $on );
			}
			return $on;
		}

		/**
		 * Wire the head script + the REST route.
		 *
		 * @return void
		 */
		public static function boot(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			require_once dirname( __DIR__ ) . '/class-lafka-beacon-guard.php';
			require_once dirname( __DIR__ ) . '/insights/class-lafka-insights-session.php';
			add_action( 'wp_head', array( __CLASS__, 'print_script' ), 1 );
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Sample rate from `lafka_log_settings[js_sample]` (default 1.0).
		 *
		 * @return float
		 */
		public static function sample_rate(): float {
			$settings = function_exists( 'get_option' ) ? get_option( 'lafka_log_settings', array() ) : array();
			$rate     = ( is_array( $settings ) && isset( $settings['js_sample'] ) && is_numeric( $settings['js_sample'] ) ) ? (float) $settings['js_sample'] : 1.0;
			return max( 0.0, min( 1.0, $rate ) );
		}

		/**
		 * Print the inline handler (front end only).
		 *
		 * @return void
		 */
		public static function print_script(): void {
			if ( ( function_exists( 'is_admin' ) && is_admin() ) || ( function_exists( 'get_query_var' ) && '' !== (string) get_query_var( 'lafka_kds_token', '' ) ) ) {
				return;
			}
			$file = dirname( __DIR__, 2 ) . '/assets/js/lafka-diag.min.js';
			$code = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local, plugin-owned file.
			if ( '' === $code ) {
				return;
			}
			$config = array(
				'u' => rest_url( 'lafka/v1/diag' ),
				's' => self::sample_rate(),
				't' => function_exists( 'lafka_analytics_page_type' ) ? lafka_analytics_page_type() : '',
			);
			echo '<script id="lafka-diag">window.lafkaDiagCfg=' . wp_json_encode( $config ) . ';' . $code . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON config + the plugin's own built script.
		}

		/**
		 * Register POST /lafka/v1/diag.
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			register_rest_route(
				'lafka/v1',
				'/diag',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				)
			);
		}

		/**
		 * Same-origin gate.
		 *
		 * @param object $request WP_REST_Request.
		 * @return true|WP_Error
		 */
		public static function permission( $request ) {
			if ( Lafka_Beacon_Guard::is_same_origin( $request ) ) {
				return true;
			}
			return new WP_Error( 'lafka_diag_origin', 'Cross-origin beacon refused.', array( 'status' => 403 ) );
		}

		/**
		 * Handle one error report.
		 *
		 * @param object $request WP_REST_Request.
		 * @return WP_REST_Response|array
		 */
		public static function handle( $request ) {
			$raw = Lafka_Beacon_Guard::body( $request, self::MAX_BODY );
			if ( null === $raw ) {
				return Lafka_Beacon_Guard::reply( 413 );
			}
			$ua = Lafka_Insights_Session::user_agent();
			if ( Lafka_Beacon_Guard::is_bot_ua( $ua ) ) {
				return Lafka_Beacon_Guard::reply( 204 );
			}
			$report = self::parse( $raw, Lafka_Beacon_Guard::site_host() );
			if ( null === $report ) {
				return Lafka_Beacon_Guard::reply( 400 );
			}
			$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : '';
			$key  = hash( 'sha256', $salt . '|' . Lafka_Insights_Session::client_ip() . '|' . Lafka_Insights_Session::ua_family( $ua ) );
			if ( Lafka_Beacon_Guard::rate_limited( 'diag', $key, 30, 500, 3600 ) ) {
				return Lafka_Beacon_Guard::reply( 429 );
			}
			$report['context']['ua'] = Lafka_Insights_Session::ua_family( $ua );
			self::log( $report['message'], $report['context'] );
			return Lafka_Beacon_Guard::reply( 204 );
		}

		/**
		 * Validate a report body. Pure.
		 *
		 * @param string $raw       Raw JSON.
		 * @param string $site_host Site host (the file must be served from it).
		 * @return array{message:string,context:array<string,mixed>}|null
		 */
		public static function parse( string $raw, string $site_host ): ?array {
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'm', 'f', 'l', 'c', 't' ) ) ) {
				return null;
			}
			$message = $data['m'] ?? '';
			$file    = $data['f'] ?? '';
			$type    = $data['t'] ?? '';
			if ( ! is_string( $message ) || ! is_string( $file ) || ! is_string( $type ) || ! is_int( $data['l'] ?? 0 ) || ! is_int( $data['c'] ?? 0 ) ) {
				return null;
			}
			$message = trim( $message );
			if ( '' === $message || preg_match( '/^Script error\.?$/', $message ) ) {
				return null;
			}
			$host = strtolower( (string) parse_url( $file, PHP_URL_HOST ) );
			if ( '' === $host || $host !== $site_host ) {
				return null;
			}
			return array(
				'message' => self::scrub( function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 200 ) : substr( $message, 0, 200 ) ),
				'context' => array(
					'code'      => 'js_error',
					'file'      => substr( (string) parse_url( $file, PHP_URL_PATH ), 0, 200 ),
					'line'      => max( 0, (int) $data['l'] ),
					'column'    => max( 0, (int) ( $data['c'] ?? 0 ) ),
					'page_type' => substr( (string) preg_replace( '/[^a-z_]/', '', $type ), 0, 16 ),
				),
			);
		}

		/**
		 * Mask e-mail addresses and long digit runs (phones, card numbers) in a
		 * message before it is logged. The full scrubber lives in the Lafka
		 * logging facade; this is the fallback when it is not loaded.
		 *
		 * @param string $message Message.
		 * @return string
		 */
		public static function scrub( string $message ): string {
			$message = (string) preg_replace( '/[^\s@"\'<>]+@[^\s@"\'<>]+\.[a-z]{2,}/i', '[email]', $message );
			return (string) preg_replace( '/\d[\d\s().-]{6,}\d/', '[number]', $message );
		}

		/**
		 * Write the error: Lafka logging facade when present, else WC logger.
		 *
		 * @param string              $message Message.
		 * @param array<string,mixed> $context Context.
		 * @return void
		 */
		private static function log( string $message, array $context ): void {
			if ( function_exists( 'lafka_log' ) ) {
				lafka_log( 'warning', 'js', $message, $context );
				return;
			}
			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				if ( is_object( $logger ) && method_exists( $logger, 'warning' ) ) {
					$logger->warning( $message, array_merge( $context, array( 'source' => 'lafka-js' ) ) );
				}
			}
		}
	}
}
