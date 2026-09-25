<?php
/**
 * Lafka_Diagnostics — module state, the daily job, place-order traces and the
 * error digest trigger (GX1 / A4, A7, A8).
 *
 * Module `diagnostics` (Lafka → Modules, default ON): gates the operator
 * surfaces — the Lafka → Diagnostics screen, the Site Health tests and the
 * daily error digest email. Core logging (Lafka_Log → WooCommerce logs +
 * incident index) is always on, whatever the module state.
 *
 * Daily job (Action Scheduler `lafka_diagnostics_daily`, group `lafka`, first
 * run 07:00 site time), always scheduled so retention runs even with the
 * module off:
 *   1. prune incidents older than the retention window (90 days);
 *   2. index new leftover WooCommerce place-order traces (WC 9.9+
 *      `place-order-debug-*` logs, deleted by WC when an attempt completes —
 *      a leftover older than 15 minutes is an attempt that never finished) as
 *      `checkout` incidents, so they outlive WC's own 3-day cleanup;
 *   3. drop expired checkout-failure counters;
 *   4. when the module is on, send the error digest if anything new happened.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Diagnostics' ) ) {

	/**
	 * Diagnostics runtime.
	 */
	final class Lafka_Diagnostics {

		const MODULE_ID          = 'diagnostics';
		const DAILY_HOOK         = 'lafka_diagnostics_daily';
		const AS_GROUP           = 'lafka';
		const LAST_RUN_OPTION    = 'lafka_log_last_daily_run';
		const SEEN_TRACES_OPTION = 'lafka_log_seen_traces';
		const SCHEDULE_CHECK     = 'lafka_log_schedule_check';
		const EMAIL_CLASS        = 'Lafka_Email_Error_Digest';

		/** Minutes before a leftover place-order trace counts as abandoned. */
		const TRACE_STALE_MINUTES = 15;

		/** Leftover trace sources remembered as already indexed. */
		const MAX_SEEN_TRACES = 300;

		/**
		 * WooCommerce place-order step markers that END an attempt successfully:
		 * `[Shortcode #6A] Order payment processed successfully`,
		 * `[Shortcode #6B] Order processed without payment`,
		 * `[Store API #9] Order processed`. WC logs them as the final step but
		 * does not always delete the log (deletion is deferred to a background
		 * batch, and skipped when a step repeats), so a leftover log is NOT
		 * proof of an unfinished attempt.
		 */
		const TRACE_SUCCESS_MARKER = '/^\[(?:Shortcode #6[A-Z]?|Store API #9)(?:::[^\]]*)?\]/';

		/** Final steps that end an attempt with an exception (`#EXPECTEDFAIL`, `#FAIL`). */
		const TRACE_FAILURE_MARKER = '/^\[(?:Shortcode|Store API) #(?:EXPECTED)?FAIL\]/';

		/** Step number at/after which the payment has been processed, per flow. */
		const TRACE_PAYMENT_RANK = array(
			'shortcode' => 6,
			'store_api' => 9,
		);

		/** Order statuses that mean the attempt went through. */
		const TRACE_PAID_STATUSES = array( 'processing', 'completed', 'on-hold' );

		/**
		 * Wire the daily job, scheduling, the digest email and Site Health.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( self::DAILY_HOOK, array( __CLASS__, 'run_daily_guarded' ) );
			add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
			add_action( 'init', array( 'Lafka_Incidents', 'maybe_install' ) );
			if ( self::is_enabled() ) {
				add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email' ) );
			}
		}

		/**
		 * Whether the module (operator surfaces + digest) is enabled.
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$settings = function_exists( 'get_option' ) ? get_option( 'lafka_log_settings', array() ) : array();
			return ! ( is_array( $settings ) && isset( $settings['diagnostics'] ) && 'disabled' === $settings['diagnostics'] );
		}

		/**
		 * Persist the module flag (the registry's set callback).
		 *
		 * @param bool $enabled State.
		 * @return void
		 */
		public static function set_enabled( bool $enabled ): void {
			$settings                = function_exists( 'get_option' ) ? get_option( 'lafka_log_settings', array() ) : array();
			$settings                = is_array( $settings ) ? $settings : array();
			$settings['diagnostics'] = $enabled ? 'enabled' : 'disabled';
			if ( function_exists( 'update_option' ) ) {
				update_option( 'lafka_log_settings', $settings );
			}
			if ( class_exists( 'Lafka_Log' ) ) {
				Lafka_Log::update_settings( array() );
			}
		}

		// ─── Scheduling ─────────────────────────────────────────────────────

		/**
		 * Make sure the daily Action Scheduler job exists (checked at most every
		 * 12 hours, admin requests only).
		 *
		 * @return void
		 */
		public static function ensure_scheduled(): void {
			if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
				return;
			}
			if ( function_exists( 'get_transient' ) && get_transient( self::SCHEDULE_CHECK ) ) {
				return;
			}
			if ( false === as_next_scheduled_action( self::DAILY_HOOK, array(), self::AS_GROUP ) ) {
				as_schedule_recurring_action( self::next_run_timestamp(), DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::AS_GROUP );
			}
			if ( function_exists( 'set_transient' ) ) {
				set_transient( self::SCHEDULE_CHECK, 1, 12 * HOUR_IN_SECONDS );
			}
		}

		/**
		 * Remove the daily job (deactivation / uninstall).
		 *
		 * @return void
		 */
		public static function unschedule(): void {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::DAILY_HOOK, array(), self::AS_GROUP );
			}
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::SCHEDULE_CHECK );
			}
		}

		/**
		 * Next 07:00 in the site timezone (filter `lafka_diagnostics_daily_hour`).
		 *
		 * @return int Unix timestamp.
		 */
		public static function next_run_timestamp(): int {
			$hour = 7;
			if ( function_exists( 'apply_filters' ) ) {
				$hour = (int) apply_filters( 'lafka_diagnostics_daily_hour', $hour );
			}
			$hour = max( 0, min( 23, $hour ) );
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$next = ( new \DateTimeImmutable( 'today', $tz ) )->setTime( $hour, 0 );
			if ( $next->getTimestamp() <= time() ) {
				$next = $next->modify( '+1 day' );
			}
			return $next->getTimestamp();
		}

		/**
		 * The daily job behind Lafka_Log::guard() (a failure is logged, never fatal).
		 *
		 * @return void
		 */
		public static function run_daily_guarded(): void {
			$job = class_exists( 'Lafka_Log' )
				? Lafka_Log::guard( array( __CLASS__, 'run_daily' ), 'cron', 'swallow' )
				: array( __CLASS__, 'run_daily' );
			call_user_func( $job );
		}

		/**
		 * The daily job.
		 *
		 * @return array<string,int> What happened (tests / Tools → Scheduled Actions log).
		 */
		public static function run_daily(): array {
			if ( function_exists( 'update_option' ) ) {
				update_option( self::LAST_RUN_OPTION, time(), false );
			}
			$summary = array(
				'pruned'  => class_exists( 'Lafka_Incidents' ) ? Lafka_Incidents::prune( self::retention_days() ) : 0,
				'traces'  => self::index_place_order_traces(),
				'digest'  => 0,
			);
			if ( class_exists( 'Lafka_Incidents' ) ) {
				Lafka_Incidents::resolve_finished_trace_incidents();
			}
			if ( class_exists( 'Lafka_Checkout_Failures' ) ) {
				Lafka_Checkout_Failures::prune_stats();
			}
			if ( self::is_enabled() ) {
				$summary['digest'] = self::send_digest();
			}
			return $summary;
		}

		/**
		 * Incident retention in days (7–365; default 90).
		 *
		 * @return int
		 */
		public static function retention_days(): int {
			$days = class_exists( 'Lafka_Log' ) ? (int) ( Lafka_Log::settings()['retention_days'] ?? 90 ) : 90;
			if ( function_exists( 'apply_filters' ) ) {
				$days = (int) apply_filters( 'lafka_incidents_retention_days', $days );
			}
			return max( 7, min( 365, $days ) );
		}

		// ─── Error digest ───────────────────────────────────────────────────

		/**
		 * `woocommerce_email_classes`: register the digest (lazy class load).
		 *
		 * @param mixed $emails Registered emails.
		 * @return array
		 */
		public static function register_email( $emails ) {
			$emails = is_array( $emails ) ? $emails : array();
			if ( ! class_exists( 'WC_Email' ) ) {
				return $emails;
			}
			if ( ! class_exists( self::EMAIL_CLASS ) ) {
				require_once __DIR__ . '/class-lafka-email-error-digest.php';
			}
			if ( class_exists( self::EMAIL_CLASS ) ) {
				$class                       = self::EMAIL_CLASS;
				$emails[ self::EMAIL_CLASS ] = new $class();
			}
			return $emails;
		}

		/**
		 * Send the digest when there is something new. Returns the number of
		 * incidents reported (0 = nothing sent).
		 *
		 * @return int
		 */
		public static function send_digest(): int {
			if ( ! self::is_enabled() || ! class_exists( 'Lafka_Incidents' ) ) {
				return 0;
			}
			$rows = Lafka_Incidents::pending_digest( 50 );
			if ( empty( $rows ) ) {
				return 0;
			}
			$email = self::digest_email();
			if ( ! is_object( $email ) || ! method_exists( $email, 'trigger' ) ) {
				return 0;
			}
			if ( ! $email->trigger( $rows ) ) {
				return 0;
			}
			$ids = array();
			foreach ( $rows as $row ) {
				$ids[] = (int) ( is_object( $row ) ? ( $row->id ?? 0 ) : ( $row['id'] ?? 0 ) );
			}
			Lafka_Incidents::mark_notified( $ids );
			return count( $rows );
		}

		/**
		 * The registered digest WC_Email instance, or null.
		 *
		 * @return object|null
		 */
		private static function digest_email() {
			if ( ! function_exists( 'WC' ) ) {
				return null;
			}
			$wc = WC();
			if ( ! is_object( $wc ) || ! method_exists( $wc, 'mailer' ) ) {
				return null;
			}
			$emails = $wc->mailer()->get_emails();
			return is_array( $emails ) && isset( $emails[ self::EMAIL_CLASS ] ) ? $emails[ self::EMAIL_CLASS ] : null;
		}

		// ─── WooCommerce place-order traces ─────────────────────────────────

		/**
		 * Leftover WooCommerce place-order traces, newest first.
		 *
		 * Reads the file log directory (WC's default file handler) or the
		 * `woocommerce_log` table (DB handler). Returns [] when neither is in
		 * use or WooCommerce predates the step logger (9.9).
		 *
		 * @param int $limit Max traces.
		 * @return array<int,array<string,mixed>>
		 */
		public static function place_order_traces( int $limit = 20 ): array {
			$handler = self::log_handler();
			if ( '' === $handler ) {
				return array();
			}
			if ( false !== stripos( $handler, 'DB' ) ) {
				return self::db_traces( $limit );
			}

			$dir = self::log_directory();
			if ( '' === $dir || ! is_dir( $dir ) ) {
				return array();
			}
			$files = glob( trailingslashit( $dir ) . 'place-order-debug-*.log' );
			if ( ! is_array( $files ) || empty( $files ) ) {
				return array();
			}
			usort(
				$files,
				static function ( $a, $b ) {
					return (int) filemtime( $b ) <=> (int) filemtime( $a );
				}
			);

			$traces = array();
			foreach ( array_slice( $files, 0, max( 1, $limit ) ) as $file ) {
				$contents = file_get_contents( $file, false, null, 0, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local WooCommerce log file.
				if ( false === $contents ) {
					continue;
				}
				$basename = basename( $file );
				$parsed   = self::parse_trace( $contents );

				$parsed['source']   = preg_match( '/^(place-order-debug-[a-f0-9]{8})/', $basename, $m ) ? $m[1] : '';
				$parsed['file_id']  = (string) preg_replace( '/-[a-f0-9]{32}\.log$/', '', $basename );
				$parsed['modified'] = (int) filemtime( $file );
				$traces[]           = self::with_outcome( $parsed );
			}
			return $traces;
		}

		/**
		 * Attach the linked order's status and the attempt outcome.
		 *
		 * @param array<string,mixed> $trace Parsed trace.
		 * @return array<string,mixed>
		 */
		private static function with_outcome( array $trace ): array {
			$trace['order_status'] = self::order_status( (int) ( $trace['order_id'] ?? 0 ) );
			$trace['outcome']      = self::trace_outcome( $trace, $trace['order_status'] );
			return $trace;
		}

		/**
		 * Parse one WC log file body into steps (pure).
		 *
		 * Line shape: `2026-05-29T20:22:14+00:00 DEBUG [Shortcode #5] message CONTEXT: {json}`.
		 *
		 * `terminal` is 'success' / 'failure' when any step is a WC terminal
		 * marker; `path` + `rank` describe the last numbered step reached
		 * ('shortcode' | 'store_api', step number).
		 *
		 * @param string $contents File contents.
		 * @return array{steps:int,last_step:string,first_step:string,order_id:int,started:string,ended:string,terminal:string,path:string,rank:int}
		 */
		public static function parse_trace( string $contents ): array {
			$out = array(
				'steps'      => 0,
				'last_step'  => '',
				'first_step' => '',
				'order_id'   => 0,
				'started'    => '',
				'ended'      => '',
				'terminal'   => '',
				'path'       => '',
				'rank'       => 0,
			);
			foreach ( preg_split( '/\r\n|\n|\r/', $contents ) as $line ) {
				if ( ! preg_match( '/^(\S+)\s+[A-Z]+\s+(.*?)(?:\s+CONTEXT:\s+(\{.*\}))?\s*$/', (string) $line, $m ) ) {
					continue;
				}
				++$out['steps'];
				$message = trim( $m[2] );
				if ( '' === $out['first_step'] ) {
					$out['first_step'] = $message;
					$out['started']    = $m[1];
				}
				$out['last_step'] = $message;
				$out['ended']     = $m[1];
				if ( preg_match( self::TRACE_SUCCESS_MARKER, $message ) ) {
					$out['terminal'] = 'success';
				} elseif ( '' === $out['terminal'] && preg_match( self::TRACE_FAILURE_MARKER, $message ) ) {
					$out['terminal'] = 'failure';
				}
				if ( preg_match( '/^\[(Shortcode|Store API) #(\d+)/', $message, $step ) ) {
					$out['path'] = 'Shortcode' === $step[1] ? 'shortcode' : 'store_api';
					$out['rank'] = (int) $step[2];
				}
				if ( ! empty( $m[3] ) ) {
					$context = json_decode( $m[3], true );
					if ( is_array( $context ) && ! empty( $context['order_id'] ) ) {
						$out['order_id'] = (int) $context['order_id'];
					}
				}
			}
			if ( class_exists( 'Lafka_Log_Scrubber' ) ) {
				$out['last_step']  = Lafka_Log_Scrubber::scrub_string( $out['last_step'] );
				$out['first_step'] = Lafka_Log_Scrubber::scrub_string( $out['first_step'] );
			}
			return $out;
		}

		/**
		 * Outcome of one place-order attempt:
		 *   'finished'   — a WC success step was reached, or the attempt got past
		 *                  the payment step and its order is paid / on hold;
		 *   'failed'     — WC logged a failure step (#EXPECTEDFAIL / #FAIL);
		 *   'unfinished' — it stopped part-way (e.g. inside the gateway, or at
		 *                  validation) — the ones worth an owner's attention.
		 * Filter: `lafka_place_order_trace_outcome`.
		 *
		 * @param array<string,mixed> $trace        parse_trace() result.
		 * @param string              $order_status Linked order's status ('' = none/unknown).
		 * @return string
		 */
		public static function trace_outcome( array $trace, string $order_status = '' ): string {
			$outcome = 'unfinished';
			$path    = (string) ( $trace['path'] ?? '' );
			$rank    = (int) ( $trace['rank'] ?? 0 );
			if ( 'success' === ( $trace['terminal'] ?? '' ) ) {
				$outcome = 'finished';
			} elseif ( in_array( $order_status, self::TRACE_PAID_STATUSES, true )
				&& isset( self::TRACE_PAYMENT_RANK[ $path ] )
				&& $rank >= self::TRACE_PAYMENT_RANK[ $path ] ) {
				$outcome = 'finished';
			} elseif ( 'failure' === ( $trace['terminal'] ?? '' ) ) {
				$outcome = 'failed';
			}
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_place_order_trace_outcome', $outcome, $trace, $order_status );
				if ( in_array( $filtered, array( 'finished', 'failed', 'unfinished' ), true ) ) {
					$outcome = $filtered;
				}
			}
			return $outcome;
		}

		/**
		 * Drop finished attempts unless asked for them (the Diagnostics
		 * "Show finished attempts" toggle).
		 *
		 * @param array<int,array<string,mixed>> $traces           Traces.
		 * @param bool                           $include_finished Keep finished ones.
		 * @return array<int,array<string,mixed>>
		 */
		public static function filter_traces( array $traces, bool $include_finished ): array {
			$out = array();
			foreach ( $traces as $trace ) {
				if ( $include_finished || 'finished' !== self::outcome_of( $trace ) ) {
					$out[] = $trace;
				}
			}
			return $out;
		}

		/**
		 * A trace's outcome, using its precomputed `outcome` / `order_status`
		 * when present (place_order_traces() sets both).
		 *
		 * @param array<string,mixed> $trace Trace.
		 * @return string
		 */
		private static function outcome_of( array $trace ): string {
			if ( isset( $trace['outcome'] ) && is_string( $trace['outcome'] ) ) {
				return $trace['outcome'];
			}
			$status = isset( $trace['order_status'] )
				? (string) $trace['order_status']
				: self::order_status( (int) ( $trace['order_id'] ?? 0 ) );
			return self::trace_outcome( $trace, $status );
		}

		/**
		 * Current status of an order ('' when unknown).
		 *
		 * @param int $order_id Order id.
		 * @return string
		 */
		private static function order_status( int $order_id ): string {
			if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
				return '';
			}
			$order = wc_get_order( $order_id );
			return is_object( $order ) && method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		}

		/**
		 * Index traces that went stale since the last run as `checkout` incidents.
		 *
		 * @param array<int,array<string,mixed>>|null $traces Traces (default: read them).
		 * @return int Traces indexed.
		 */
		public static function index_place_order_traces( ?array $traces = null ): int {
			if ( ! class_exists( 'Lafka_Log' ) ) {
				return 0;
			}
			$traces = null === $traces ? self::place_order_traces( 100 ) : $traces;
			if ( empty( $traces ) ) {
				return 0;
			}
			$seen = function_exists( 'get_option' ) ? get_option( self::SEEN_TRACES_OPTION, array() ) : array();
			$seen = is_array( $seen ) ? $seen : array();

			$indexed = 0;
			$cutoff  = time() - self::TRACE_STALE_MINUTES * 60;
			foreach ( $traces as $trace ) {
				$source = (string) ( $trace['source'] ?? '' );
				if ( '' === $source || in_array( $source, $seen, true ) || (int) ( $trace['modified'] ?? 0 ) > $cutoff ) {
					continue;
				}
				if ( 'finished' === self::outcome_of( $trace ) ) {
					continue; // A completed checkout whose log WC has not deleted (yet).
				}
				Lafka_Log::warning(
					'checkout',
					sprintf( 'Checkout attempt did not finish; last step: %s', (string) ( $trace['last_step'] ?? '' ) ),
					array(
						'code'     => 'place_order_incomplete',
						'order_id' => (int) ( $trace['order_id'] ?? 0 ),
						'trace'    => $source,
						'steps'    => (int) ( $trace['steps'] ?? 0 ),
					)
				);
				$seen[] = $source;
				++$indexed;
			}
			if ( $indexed > 0 && function_exists( 'update_option' ) ) {
				update_option( self::SEEN_TRACES_OPTION, array_slice( $seen, - self::MAX_SEEN_TRACES ), false );
			}
			return $indexed;
		}

		/**
		 * Traces from the DB log handler, grouped by source.
		 *
		 * @param int $limit Max traces.
		 * @return array<int,array<string,mixed>>
		 */
		private static function db_traces( int $limit ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
				return array();
			}
			$table = $wpdb->prefix . 'woocommerce_log';
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own log table name (prefix concatenation).
					"SELECT source, timestamp, level, message, context FROM {$table} WHERE source LIKE %s ORDER BY log_id ASC LIMIT 2000",
					$wpdb->esc_like( 'place-order-debug-' ) . '%'
				)
			);
			if ( ! is_array( $rows ) ) {
				return array();
			}
			$by_source = array();
			foreach ( $rows as $row ) {
				$by_source[ (string) $row->source ][] = sprintf( '%s DEBUG %s CONTEXT: %s', str_replace( ' ', 'T', (string) $row->timestamp ), (string) $row->message, (string) $row->context );
			}
			$traces = array();
			foreach ( $by_source as $source => $lines ) {
				$parsed             = self::parse_trace( implode( "\n", $lines ) );
				$parsed['source']   = $source;
				$parsed['file_id']  = '';
				$parsed['modified'] = (int) strtotime( (string) $parsed['ended'] );
				$traces[]           = self::with_outcome( $parsed );
			}
			usort(
				$traces,
				static function ( $a, $b ) {
					return $b['modified'] <=> $a['modified'];
				}
			);
			return array_slice( $traces, 0, max( 1, $limit ) );
		}

		// ─── WooCommerce logging facts (for Health) ─────────────────────────

		/**
		 * WooCommerce's default log handler class, or '' when unknown.
		 *
		 * @return string
		 */
		public static function log_handler(): string {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil', 'get_default_handler' ) ) {
				return (string) \Automattic\WooCommerce\Utilities\LoggingUtil::get_default_handler();
			}
			return defined( 'WC_LOG_HANDLER' ) ? (string) WC_LOG_HANDLER : '';
		}

		/**
		 * WooCommerce's log directory, or ''.
		 *
		 * @return string
		 */
		public static function log_directory(): string {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil', 'get_log_directory' ) ) {
				return (string) \Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory();
			}
			return defined( 'WC_LOG_DIR' ) ? (string) WC_LOG_DIR : '';
		}

		/**
		 * WooCommerce → Status → Logs URL, optionally filtered to one source.
		 *
		 * @param string $source WC log source (e.g. lafka-checkout).
		 * @return string
		 */
		public static function logs_url( string $source = '' ): string {
			$base = class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil', 'get_logs_tab_url' )
				? (string) \Automattic\WooCommerce\Utilities\LoggingUtil::get_logs_tab_url()
				: ( function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wc-status&tab=logs' ) : '' );
			return '' !== $source && function_exists( 'add_query_arg' ) ? add_query_arg( 'source', $source, $base ) : $base;
		}

		/**
		 * WC log viewer URL for one file id (FileV2 handler).
		 *
		 * @param string $file_id File id (basename without hash).
		 * @return string
		 */
		public static function log_file_url( string $file_id ): string {
			if ( '' === $file_id || ! function_exists( 'add_query_arg' ) ) {
				return self::logs_url();
			}
			return add_query_arg(
				array(
					'view'    => 'single_file',
					'file_id' => $file_id,
				),
				self::logs_url()
			);
		}
	}
}
