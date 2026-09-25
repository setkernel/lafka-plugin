<?php
/**
 * Lafka_Log — the one logging facade for Lafka code (GX1 / A1).
 *
 *     Lafka_Log::error( 'payment', 'Gateway timed out', array( 'code' => 'gateway_timeout', 'order_id' => 123 ) );
 *     lafka_log( 'warning', 'shipping', 'Zone polygon is empty' );
 *
 * Records go to WooCommerce's logger (`wc_get_logger()`), one WC source per
 * channel (`lafka-{channel}`), so they appear in WooCommerce → Status → Logs
 * with WC's viewer, filtering and retention. Nothing new to operate.
 *
 * Every record:
 *   - is dropped cheaply when below the minimum level — `warning` by default,
 *     `debug` when WP_DEBUG is on; option `lafka_log_settings[min_level]` and
 *     the per-channel `lafka_log_min_level` filter override it;
 *   - has its message + context scrubbed of personal data (Lafka_Log_Scrubber);
 *   - carries an envelope: request_id, channel, code, url_path (no query
 *     string) and lafka_version.
 *
 * Records at `warning` or above are also upserted into the incident index
 * (Lafka_Incidents — "what is broken and how often") and fire
 * `do_action( 'lafka_log_record', $record )`, the forwarding point for Sentry
 * or a webhook. The facade never throws and never recurses.
 *
 * Per-request correlation: request_id() is 16 hex characters generated once
 * per request. It is returned as the `X-Lafka-Request-Id` header on Lafka REST
 * and Store API responses (and the classic checkout AJAX response) and saved
 * on orders created at checkout as `_lafka_request_id`, so a failed order leads
 * straight to its log lines.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Log' ) ) {

	/**
	 * Logging facade over wc_get_logger().
	 */
	final class Lafka_Log {

		/** Settings option: min_level, diagnostics (module flag), retention_days. */
		const OPTION = 'lafka_log_settings';

		/** Response header carrying the request id. */
		const HEADER = 'X-Lafka-Request-Id';

		/** Order meta holding the request id of the checkout that created it. */
		const ORDER_META = '_lafka_request_id';

		/** PSR-3 / WC_Log_Levels severities. */
		const LEVELS = array(
			'debug'     => 100,
			'info'      => 200,
			'notice'    => 300,
			'warning'   => 400,
			'error'     => 500,
			'critical'  => 600,
			'alert'     => 700,
			'emergency' => 800,
		);

		/** Records at or above this level are indexed as incidents. */
		const INCIDENT_LEVEL = 'warning';

		/** Documented channels (any sanitized channel is accepted). */
		const CHANNELS = array(
			'core',
			'checkout',
			'payment',
			'store-api',
			'order-hours',
			'shipping',
			'timeslots',
			'addons',
			'kds',
			'conversion',
			'analytics',
			'js',
			'theme',
			'child',
			'cron',
			'rest',
			'php',
		);

		/** @var string|null */
		private static $request_id = null;

		/** @var int Re-entrancy depth (a listener that logs never loops). */
		private static $depth = 0;

		/** @var array<string,mixed>|null */
		private static $settings = null;

		/** @var object|null Injected logger (tests); null = wc_get_logger(). */
		private static $logger = null;

		/** @var string|null */
		private static $version = null;

		// ─── Level shorthands ───────────────────────────────────────────────

		/**
		 * @param string $channel Channel.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function debug( string $channel, string $message, array $context = array() ): bool {
			return self::log( 'debug', $channel, $message, $context );
		}

		/**
		 * @param string $channel Channel.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function info( string $channel, string $message, array $context = array() ): bool {
			return self::log( 'info', $channel, $message, $context );
		}

		/**
		 * @param string $channel Channel.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function notice( string $channel, string $message, array $context = array() ): bool {
			return self::log( 'notice', $channel, $message, $context );
		}

		/**
		 * @param string $channel Channel.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function warning( string $channel, string $message, array $context = array() ): bool {
			return self::log( 'warning', $channel, $message, $context );
		}

		/**
		 * @param string $channel Channel.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function error( string $channel, string $message, array $context = array() ): bool {
			return self::log( 'error', $channel, $message, $context );
		}

		/**
		 * @param string $channel Channel.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function critical( string $channel, string $message, array $context = array() ): bool {
			return self::log( 'critical', $channel, $message, $context );
		}

		// ─── Core ───────────────────────────────────────────────────────────

		/**
		 * Write one record. Never throws.
		 *
		 * @param string $level   debug|info|notice|warning|error|critical|alert|emergency.
		 * @param string $channel Channel (see CHANNELS); becomes WC source `lafka-{channel}`.
		 * @param string $message Human-readable message (scrubbed).
		 * @param mixed  $context Structured context (scrubbed). `code`, `file`, `line` are recognised.
		 * @return bool True when the record was written.
		 */
		public static function log( $level, $channel, $message, $context = array() ): bool {
			if ( self::$depth > 0 ) {
				return false;
			}
			++self::$depth;
			try {
				$level   = self::normalize_level( $level );
				$channel = self::normalize_channel( $channel );
				if ( ! self::should_log( $level, $channel ) ) {
					return false;
				}

				$record = self::build_record( $level, $channel, (string) $message, is_array( $context ) ? $context : array( 'value' => $context ) );
				self::write( $record );

				if ( self::severity( $level ) >= self::severity( self::INCIDENT_LEVEL ) ) {
					if ( class_exists( 'Lafka_Incidents' ) ) {
						Lafka_Incidents::record( $record );
					}
					if ( function_exists( 'do_action' ) ) {
						do_action( 'lafka_log_record', $record );
					}
				}
				return true;
			} catch ( \Throwable $e ) {
				return false;
			} finally {
				--self::$depth;
			}
		}

		/**
		 * Build the normalized, scrubbed record.
		 *
		 * @param string $level   Level.
		 * @param string $channel Channel.
		 * @param string $message Raw message.
		 * @param array  $context Raw context.
		 * @return array<string,mixed>
		 */
		public static function build_record( string $level, string $channel, string $message, array $context ): array {
			$code = isset( $context['code'] ) && is_scalar( $context['code'] ) ? self::sanitize_code( (string) $context['code'] ) : '';
			$file = isset( $context['file'] ) && is_string( $context['file'] ) ? Lafka_Log_Scrubber::relative_path( $context['file'] ) : '';
			$line = isset( $context['line'] ) && is_numeric( $context['line'] ) ? (int) $context['line'] : 0;
			if ( '' !== $file ) {
				$context['file'] = $file;
			}

			$scrubbed = Lafka_Log_Scrubber::scrub( $context );
			unset( $scrubbed['code'] );

			$envelope = array(
				'request_id'    => self::request_id(),
				'channel'       => $channel,
				'code'          => $code,
				'url_path'      => self::current_path(),
				'lafka_version' => self::plugin_version(),
			);

			return array(
				'level'      => $level,
				'channel'    => $channel,
				'source'     => self::source( $channel ),
				'code'       => $code,
				'message'    => Lafka_Log_Scrubber::scrub_string( $message ),
				'context'    => array_merge( $scrubbed, $envelope ),
				'request_id' => $envelope['request_id'],
				'file'       => $file,
				'line'       => $line,
				'timestamp'  => time(),
			);
		}

		/**
		 * Hand a record to the WC logger (or error_log when WC is absent and
		 * WP_DEBUG_LOG is on).
		 *
		 * @param array<string,mixed> $record Record.
		 * @return void
		 */
		private static function write( array $record ): void {
			$logger = self::logger();
			if ( is_object( $logger ) && method_exists( $logger, 'log' ) ) {
				$logger->log( $record['level'], $record['message'], array_merge( $record['context'], array( 'source' => $record['source'] ) ) );
				return;
			}
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record['context'] ) : json_encode( $record['context'] );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- documented fallback when WooCommerce's logger is unavailable.
				error_log( sprintf( '[%s] %s: %s %s', $record['source'], strtoupper( $record['level'] ), $record['message'], (string) $json ) );
			}
		}

		/**
		 * @return object|null
		 */
		private static function logger() {
			if ( null !== self::$logger ) {
				return self::$logger;
			}
			return function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
		}

		// ─── Levels, channels, thresholds ───────────────────────────────────

		/**
		 * Numeric severity of a level (unknown → notice).
		 *
		 * @param string $level Level.
		 * @return int
		 */
		public static function severity( string $level ): int {
			return self::LEVELS[ strtolower( $level ) ] ?? self::LEVELS['notice'];
		}

		/**
		 * @param mixed $level Level.
		 * @return string A valid level (unknown → notice).
		 */
		public static function normalize_level( $level ): string {
			$level = is_string( $level ) ? strtolower( trim( $level ) ) : '';
			return isset( self::LEVELS[ $level ] ) ? $level : 'notice';
		}

		/**
		 * @param mixed $channel Channel.
		 * @return string [a-z0-9-], max 32 chars; empty → core.
		 */
		public static function normalize_channel( $channel ): string {
			$channel = is_string( $channel ) ? strtolower( trim( $channel ) ) : '';
			$channel = (string) preg_replace( '/[^a-z0-9\-]+/', '-', str_replace( '_', '-', $channel ) );
			$channel = trim( substr( $channel, 0, 32 ), '-' );
			return '' === $channel ? 'core' : $channel;
		}

		/**
		 * WC log source for a channel.
		 *
		 * @param string $channel Channel.
		 * @return string
		 */
		public static function source( string $channel ): string {
			return 'lafka-' . self::normalize_channel( $channel );
		}

		/**
		 * The effective minimum level for a channel.
		 *
		 * @param string $channel Channel.
		 * @return string
		 */
		public static function min_level( string $channel = 'core' ): string {
			$configured = (string) ( self::settings()['min_level'] ?? '' );
			if ( isset( self::LEVELS[ $configured ] ) ) {
				$level = $configured;
			} else {
				$level = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'debug' : 'warning';
			}
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_log_min_level', $level, $channel );
				if ( is_string( $filtered ) && isset( self::LEVELS[ $filtered ] ) ) {
					$level = $filtered;
				}
			}
			return $level;
		}

		/**
		 * Whether a record at $level on $channel would be written.
		 *
		 * @param string $level   Level.
		 * @param string $channel Channel.
		 * @return bool
		 */
		public static function should_log( string $level, string $channel = 'core' ): bool {
			return self::severity( $level ) >= self::severity( self::min_level( $channel ) );
		}

		/**
		 * The `lafka_log_settings` option, with defaults.
		 *
		 * @return array<string,mixed>
		 */
		public static function settings(): array {
			if ( null === self::$settings ) {
				$stored         = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
				self::$settings = array_merge(
					array(
						'min_level'      => '',
						'diagnostics'    => 'enabled',
						'retention_days' => 90,
					),
					is_array( $stored ) ? $stored : array()
				);
			}
			return self::$settings;
		}

		/**
		 * Persist settings (merged) and bust the request cache.
		 *
		 * @param array<string,mixed> $changes Changed keys.
		 * @return void
		 */
		public static function update_settings( array $changes ): void {
			$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
			$stored = is_array( $stored ) ? $stored : array();
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTION, array_merge( $stored, $changes ) );
			}
			self::$settings = null;
		}

		// ─── Correlation ────────────────────────────────────────────────────

		/**
		 * 16 hex characters, stable for the whole request.
		 *
		 * @return string
		 */
		public static function request_id(): string {
			if ( null === self::$request_id ) {
				try {
					self::$request_id = bin2hex( random_bytes( 8 ) );
				} catch ( \Throwable $e ) {
					self::$request_id = substr( md5( uniqid( '', true ) ), 0, 16 );
				}
			}
			return self::$request_id;
		}

		/**
		 * Request path only (no query string, no host), scrubbed, ≤ 200 chars.
		 *
		 * @return string
		 */
		private static function current_path(): string {
			if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! function_exists( 'wp_unslash' ) || ! function_exists( 'sanitize_text_field' ) ) {
				return '';
			}
			$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			return is_string( $path ) ? substr( Lafka_Log_Scrubber::scrub_string( $path ), 0, 200 ) : '';
		}

		/**
		 * Plugin version from the header (read once, only when a record is written).
		 *
		 * @return string
		 */
		private static function plugin_version(): string {
			if ( null === self::$version ) {
				self::$version = '';
				if ( defined( 'LAFKA_PLUGIN_FILE' ) && function_exists( 'get_file_data' ) ) {
					$data          = get_file_data( LAFKA_PLUGIN_FILE, array( 'Version' => 'Version' ) );
					self::$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
				}
			}
			return self::$version;
		}

		/**
		 * @param string $code Raw code.
		 * @return string [A-Za-z0-9_.-], ≤ 64 chars.
		 */
		public static function sanitize_code( string $code ): string {
			return substr( (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', $code ), 0, 64 );
		}

		// ─── Guard ──────────────────────────────────────────────────────────

		/**
		 * Wrap a callable so an uncaught Throwable is logged (with channel
		 * context) instead of disappearing into a generic fatal.
		 *
		 * @param callable $callback Callback to protect.
		 * @param string   $channel  Channel for the error record (rest, cron, kds…).
		 * @param string   $on_error 'rethrow' (default) | 'wp_error' (REST: 500 WP_Error) | 'swallow' (cron: return null).
		 * @return \Closure
		 */
		public static function guard( callable $callback, string $channel = 'core', string $on_error = 'rethrow' ): \Closure {
			return static function ( ...$args ) use ( $callback, $channel, $on_error ) {
				try {
					return $callback( ...$args );
				} catch ( \Throwable $e ) {
					return self::handle_guarded_throwable( $e, $callback, $channel, $on_error );
				}
			};
		}

		/**
		 * Log a Throwable caught by guard() and apply the error policy.
		 *
		 * @param \Throwable $e        Caught throwable.
		 * @param mixed      $callback The guarded callable (for the message).
		 * @param string     $channel  Channel.
		 * @param string     $on_error Policy.
		 * @return mixed
		 * @throws \Throwable When the policy is 'rethrow'.
		 */
		public static function handle_guarded_throwable( \Throwable $e, $callback, string $channel, string $on_error ) {
			self::error(
				$channel,
				sprintf( 'Uncaught %1$s in %2$s: %3$s', get_class( $e ), self::callable_name( $callback ), $e->getMessage() ),
				array(
					'code'      => 'uncaught_exception',
					'exception' => $e,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
				)
			);
			if ( 'wp_error' === $on_error && class_exists( 'WP_Error' ) ) {
				return new \WP_Error(
					'lafka_internal_error',
					function_exists( '__' ) ? __( 'Something went wrong. Please try again.', 'lafka-plugin' ) : 'Something went wrong. Please try again.',
					array( 'status' => 500 )
				);
			}
			if ( 'swallow' === $on_error ) {
				return null;
			}
			throw $e;
		}

		/**
		 * Readable name of a callable for messages.
		 *
		 * @param mixed $callback Callable.
		 * @return string
		 */
		public static function callable_name( $callback ): string {
			if ( is_string( $callback ) ) {
				return $callback;
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				return $owner . '::' . (string) $callback[1];
			}
			if ( $callback instanceof \Closure ) {
				return 'closure';
			}
			return is_object( $callback ) ? get_class( $callback ) : 'callable';
		}

		// ─── WordPress wiring ───────────────────────────────────────────────

		/**
		 * Register the facade's hooks (bridge, correlation, REST guard).
		 *
		 * @return void
		 */
		public static function register_hooks(): void {
			add_action( 'lafka_log', array( __CLASS__, 'on_bridge_log' ), 10, 4 );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_rest_header' ), 10, 3 );
			add_filter( 'rest_dispatch_request', array( __CLASS__, 'guard_rest_dispatch' ), 10, 4 );
			add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'send_checkout_header' ) );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ), 10, 1 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'stamp_order' ), 20, 1 );
		}

		/**
		 * `lafka_log` action → facade. The theme and child emit through this
		 * action so they never hard-depend on the plugin.
		 *
		 * @param mixed $level   Level.
		 * @param mixed $channel Channel (default theme).
		 * @param mixed $message Message.
		 * @param mixed $context Context.
		 * @return void
		 */
		public static function on_bridge_log( $level = 'info', $channel = 'theme', $message = '', $context = array() ): void {
			self::log( $level, is_string( $channel ) && '' !== $channel ? $channel : 'theme', is_scalar( $message ) ? (string) $message : '', is_array( $context ) ? $context : array() );
		}

		/**
		 * Add X-Lafka-Request-Id to Lafka REST + Store API responses.
		 *
		 * @param mixed $result  Response.
		 * @param mixed $server  REST server (unused).
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public static function add_rest_header( $result, $server = null, $request = null ) {
			unset( $server );
			if ( is_object( $result ) && method_exists( $result, 'header' )
				&& is_object( $request ) && method_exists( $request, 'get_route' )
				&& self::is_lafka_route( (string) $request->get_route(), true ) ) {
				$result->header( self::HEADER, self::request_id() );
			}
			return $result;
		}

		/**
		 * Run Lafka REST callbacks inside guard(): an uncaught Throwable becomes
		 * a logged `rest` incident + a clean 500 WP_Error instead of a fatal.
		 * Only Lafka namespaces (lafka/*, wc-lafka/*) are touched.
		 *
		 * @param mixed  $dispatch_result Result from an earlier filter (null = not handled).
		 * @param mixed  $request         WP_REST_Request.
		 * @param string $route           Matched route.
		 * @param array  $handler         Route handler.
		 * @return mixed
		 */
		public static function guard_rest_dispatch( $dispatch_result, $request = null, $route = '', $handler = array() ) {
			if ( null !== $dispatch_result || ! self::is_lafka_route( (string) $route, false ) ) {
				return $dispatch_result;
			}
			$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
			if ( ! is_callable( $callback ) ) {
				return $dispatch_result;
			}
			$result = self::guard( $callback, 'rest', 'wp_error' )( $request );
			if ( null === $result && class_exists( 'WP_REST_Response' ) ) {
				// Mirror core: a callback returning null is an empty response,
				// never "not handled" (which would run the callback twice).
				return new \WP_REST_Response( null );
			}
			return $result;
		}

		/**
		 * Whether a REST route belongs to Lafka (optionally counting the Store API).
		 *
		 * @param string $route           Route.
		 * @param bool   $include_store_api Also match /wc/store routes.
		 * @return bool
		 */
		public static function is_lafka_route( string $route, bool $include_store_api ): bool {
			if ( 0 === strpos( $route, '/lafka/' ) || 0 === strpos( $route, '/wc-lafka/' ) ) {
				return true;
			}
			return $include_store_api && 0 === strpos( $route, '/wc/store' );
		}

		/**
		 * Classic checkout AJAX: expose the request id to the browser/devtools.
		 *
		 * @return void
		 */
		public static function send_checkout_header(): void {
			if ( ! headers_sent() ) {
				header( self::HEADER . ': ' . self::request_id() );
			}
		}

		/**
		 * Save the request id on an order being created at checkout.
		 *
		 * @param mixed $order WC_Order.
		 * @return void
		 */
		public static function stamp_order( $order ): void {
			if ( is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
				$order->update_meta_data( self::ORDER_META, self::request_id() );
			}
		}

		// ─── Test seams ─────────────────────────────────────────────────────

		/**
		 * Inject a logger (tests) — any object with log( $level, $message, $context ).
		 *
		 * @param object|null $logger Logger or null for wc_get_logger().
		 * @return void
		 */
		public static function set_logger( $logger ): void {
			self::$logger = $logger;
		}

		/**
		 * Reset per-request state (tests; long-running workers).
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$request_id = null;
			self::$settings   = null;
			self::$logger     = null;
			self::$version    = null;
			self::$depth      = 0;
		}
	}
}
