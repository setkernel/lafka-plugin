<?php
/**
 * Observability bootstrap (GX1 · Diagnostics).
 *
 * Always loaded (logging is core, not a module):
 *   - Lafka_Log              facade over wc_get_logger() + `lafka_log()` helper
 *   - Lafka_Log_Scrubber     PII scrubber for every record
 *   - Lafka_Incidents        deduplicated incident table
 *   - Lafka_Fatal_Capture    Lafka-scoped PHP fatal capture
 *   - Lafka_Checkout_Block_Reasons + Lafka_Checkout_Failures
 *                            the `lafka_checkout_blocked` "why no order" feed
 *   - Lafka_Diagnostics      daily job (Action Scheduler), place-order traces,
 *                            digest trigger
 *
 * Gated on the `diagnostics` module (Lafka → Modules, default ON): the
 * Lafka → Diagnostics screen, Site Health tests and the daily digest email.
 *
 * Front-end cost: none — no assets, no queries on a normal page view (the
 * settings option is autoloaded; incidents are only written at warning+).
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-lafka-checkout-block-reasons.php';
require_once __DIR__ . '/class-lafka-log-scrubber.php';
require_once __DIR__ . '/class-lafka-log.php';
require_once __DIR__ . '/class-lafka-incidents.php';
require_once __DIR__ . '/class-lafka-fatal-capture.php';
require_once __DIR__ . '/class-lafka-checkout-failures.php';
require_once __DIR__ . '/class-lafka-diagnostics.php';

if ( ! function_exists( 'lafka_log' ) ) {
	/**
	 * Procedural wrapper around Lafka_Log::log().
	 *
	 * @param string $level   debug|info|notice|warning|error|critical.
	 * @param string $channel Channel (WC source `lafka-{channel}`).
	 * @param string $message Message (scrubbed).
	 * @param array  $context Context (scrubbed); `code` is a stable machine code.
	 * @return bool Whether the record was written.
	 */
	function lafka_log( $level, $channel, $message, $context = array() ) {
		return Lafka_Log::log( $level, $channel, $message, $context );
	}
}

if ( ! function_exists( 'lafka_guarded' ) ) {
	/**
	 * Wrap a callback so an uncaught Throwable is logged. Cron callbacks use the
	 * default 'swallow' policy (log it, let the next scheduled event run).
	 *
	 * @param callable $callback Callback.
	 * @param string   $channel  Channel for the error record.
	 * @param string   $on_error 'swallow' | 'rethrow' | 'wp_error'.
	 * @return callable
	 */
	function lafka_guarded( $callback, $channel = 'cron', $on_error = 'swallow' ) {
		return is_callable( $callback ) ? Lafka_Log::guard( $callback, (string) $channel, (string) $on_error ) : $callback;
	}
}

Lafka_Log::register_hooks();
Lafka_Fatal_Capture::register();
Lafka_Checkout_Failures::register();
Lafka_Diagnostics::register();

if ( Lafka_Diagnostics::is_enabled() && function_exists( 'is_admin' ) && is_admin() ) {
	require_once __DIR__ . '/class-lafka-diagnostics-health.php';
	Lafka_Diagnostics_Health::register();
	require_once dirname( __DIR__ ) . '/admin/class-lafka-diagnostics-page.php';
}
